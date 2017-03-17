<?php
/*-------------------------------------------------------------+
| GP StreetImporter Record Handlers                            |
| Copyright (C) 2017 SYSTOPIA                                  |
| Author: B. Endres (endres -at- systopia.de)                  |
|         M. McAndrew (michaelmcandrew@thirdsectordesign.org)  |
| http://www.systopia.de/                                      |
+--------------------------------------------------------------*/


/**
 * GP TEDI Handler
 *
 * @author Björn Endres (SYSTOPIA) <endres@systopia.de>
 * @license AGPL-3.0
 */
class CRM_Streetimport_GP_Handler_TEDIContactRecordHandler extends CRM_Streetimport_GP_Handler_TMRecordHandler {

  /**
   * Check if the given handler implementation can process the record
   *
   * @param $record  an array of key=>value pairs
   * @return true or false
   */
  public function canProcessRecord($record, $sourceURI) {
    $parsedFileName = $this->parseTmFile($sourceURI);
    return ($parsedFileName && $parsedFileName['file_type'] == 'Kontakte' && $parsedFileName['tm_company'] == 'tedi');
  }

  /**
   * process the given record
   *
   * @param $record  an array of key=>value pairs
   * @return true
   * @throws exception if failed
   */
  public function processRecord($record, $sourceURI) {
    $config = CRM_Streetimport_Config::singleton();
    $this->file_name_data = $this->parseTmFile($sourceURI);

    $contact_id = $this->getContactID($record);
    if (empty($contact_id)) {
      return $this->logger->logError("Contact [{$record['id']}] couldn't be identified.", $record);
    }

    // apply contact base data updates if provided
    // FIELDS: nachname  vorname firma TitelAkademisch TitelAdel TitelAmt  Anrede  geburtsdatum  geburtsjahr strasse hausnummer  hausnummernzusatz PLZ Ort email
    $this->performContactBaseUpdates($contact_id, $record);

    // Sign up for newsletter
    // FIELDS: emailNewsletter
    if ($this->isTrue($record, 'emailNewsletter')) {
      $newsletter_group_id = $config->getNewsletterGroupID();
      $this->addContactToGroup($contact_id, $newsletter_group_id, $record);
    }

    // Create / update contract
    // FIELDS: Vertragsnummer  Bankleitzahl  Kontonummer Bic Iban  Kontoinhaber  Bankinstitut  Einzugsstart  JahresBetrag  BuchungsBetrag  Einzugsintervall  EinzugsEndeDatum
    if (empty($record['Vertragsnummer'])) {
      $this->createContract($contact_id, $record);
    } else {
      $this->updateContract($record['Vertragsnummer'], $contact_id, $record);
    }

    // Add a note if requested
    // FIELDS: BemerkungFreitext
    if (!empty($record['BemerkungFreitext'])) {
      $this->createNote($contact_id, $config->translate('TM Import Note'), $record['BemerkungFreitext'], $record);
    }

    // If "X" then set  "rts_counter" in table "civicrm_value_address_statistics"  to "0"
    // FIELDS: AdresseGeprueft
    if ($this->isTrue($record, 'AdresseGeprueft')) {
      $this->addressValidated($contact_id, $record);
    }

    // TODO: Internal selectionid in IMB which is used to connect this row to the correct activityentry
    // FIELDS: Selektionshistoryid, zielgruppeid

    // weiterbuchen
    // This field kann take "0", "1" and "".
    //    "1" => no break (normal data entry for contract data and Sepa DD issue date will be set as soon as possible;
    //    "0" => break! (If there is a debit planned inbetween of date in field AA (Einzugsstart) and import date) the contract shall be paused and NOT debited asap.
    //    ""  => nothing happens

    // Ergebnisnummer  ErgebnisText
    // result response as sketched in doc "20140331_Telefonie_Response"


    // process additional fields
    // FIELDS: Bemerkung1  Bemerkung2  Bemerkung3  Bemerkung4  Bemerkung5 ...
    for ($i=1; $i <= 10; $i++) {
      if (!empty($record["Bemerkung{$i}"])) {
        $this->processAdditionalFeature($record["Bemerkung{$i}"], $contact_id, $record);
      }
    }

    $this->logger->logImport($record, true, $config->translate('TM Contact'));
  }

  /**
   * Extracts the specific activity date for this line
   */
  protected function getActivityDate($record) {
    return date('Y-m-d', strtotime($record['TagDerTelefonie']));
  }

  /**
   * apply contact base date updates (if present in the data)
   * FIELDS: nachname  vorname firma TitelAkademisch TitelAdel TitelAmt  Anrede  geburtsdatum  geburtsjahr strasse hausnummer  hausnummernzusatz PLZ Ort email
   */
  public function performContactBaseUpdates($contact_id, $record) {
    $config = CRM_Streetimport_Config::singleton();

    // ---------------------------------------------
    // |            Contact Entity                 |
    // ---------------------------------------------
    $contact_base_attributes = array(
      'nachname'        => 'last_name',
      'vorname'         => 'first_name',
      'firma'           => 'current_employer',
      'Anrede'          => 'prefix_id',
      'geburtsdatum'    => 'birth_date',
      'geburtsjahr'     => $config->getGPCustomFieldKey('birth_year'),
      'TitelAkademisch' => 'formal_title_1',
      'TitelAdel'       => 'formal_title_2',
      'TitelAmt'        => 'formal_title_3');

    // extract attributes
    $contact_base_update = array();
    foreach ($contact_base_attributes as $record_key => $civi_key) {
      if (!empty($record[$record_key])) {
        $contact_base_update[$civi_key] = trim($record[$record_key]);
      }
    }

    // compile formal title
    $formal_title = '';
    for ($i=1; $i <= 3; $i++) {
      if (isset($contact_base_update["formal_title_{$i}"])) {
        $formal_title = trim($formal_title . ' ' . $contact_base_update["formal_title_{$i}"]);
        unset($contact_base_update["formal_title_{$i}"]);
      }
    }
    if (!empty($formal_title)) {
      $contact_base_update['formal_title'] = $formal_title;
    }

    // update contact
    if (!empty($contact_base_update)) {
      $contact_base_update['id'] = $contact_id;
      civicrm_api3('Contact', 'create', $contact_base_update);
      $this->logger->logDebug("Contact [{$contact_id}] base data updated: " . json_encode($contact_base_update), $record);
    }

    // ---------------------------------------------
    // |            Address Entity                 |
    // ---------------------------------------------
    $address_base_attributes = array(
      'PLZ'               => 'postal_code',
      'Ort'               => 'city',
      'strasse'           => 'street_address_1',
      'hausnummer'        => 'street_address_2',
      'hausnummernzusatz' => 'street_address_3'
      );

    // extract attributes
    $address_update = array();
    foreach ($address_base_attributes as $record_key => $civi_key) {
      if (!empty($record[$record_key])) {
        $address_update[$civi_key] = trim($record[$record_key]);
      }
    }

    // compile street address
    $street_address = '';
    for ($i=1; $i <= 3; $i++) {
      if (isset($address_update["street_address_{$i}"])) {
        $street_address = trim($street_address . ' ' . $address_update["street_address_{$i}"]);
        unset($address_update["street_address_{$i}"]);
      }
    }
    if (!empty($street_address)) {
      $address_update['street_address'] = $street_address;
    }

    // update address
    if (!empty($address_update)) {
      $address_update['id'] = $this->getAddressId($record);
      civicrm_api3('Address', 'create', $address_update);
      $this->logger->logDebug("Contact [{$contact_id}] address updated: " . json_encode($address_update), $record);
    }


    // ---------------------------------------------
    // |             Email Entity                  |
    // ---------------------------------------------
    if (!empty($record['email'])) {
      $email = trim($record['email']);
      // see if it's already there
      $existing_emails = civicrm_api3('Email', 'get', array('email' => $email, 'contact_id' => $contact_id));
      if ($existing_emails['count'] > 0) {
        $this->logger->logDebug("Contact [{$contact_id}] already has email '{$email}'", $record);
      } else {
        civicrm_api3('Email', 'create', array(
          'contact_id'       => $contact_id,
          'email'            => $email,
          'location_type_id' => $config->getLocationTypeId()));
        $this->logger->logDebug("Contact [{$contact_id}] address updated: " . json_encode($address_update), $record);
      }
    }
  }

  /**
   * create a new contract
   *
   * FIELDS: Vertragsnummer  Bankleitzahl  Kontonummer Bic Iban  Kontoinhaber  Bankinstitut  Einzugsstart  JahresBetrag  BuchungsBetrag  Einzugsintervall  EinzugsEndeDatum
   */
  public function createContract($contact_id, $record) {
    // TODO: implement
  }

  /**
   * update an existing contract:
   * If contractId is set, then all changes in column U-AE are related to this contractID.
   * In conversion projects you will find no contractid here, which means you have to create a new one,
   * if the response in field AM/AN is positive and there is data in columns U-AE.
   *
   * FIELDS: Vertragsnummer  Bankleitzahl  Kontonummer Bic Iban  Kontoinhaber  Bankinstitut  Einzugsstart  JahresBetrag  BuchungsBetrag  Einzugsintervall  EinzugsEndeDatum
   */
  public function updateContract($contract_id, $contact_id, $record) {
    // TODO: implement
  }

  /**
   * mark the given address as valid by resetting the RTS counter
   */
  public function addressValidated($contact_id, $record) {
    // TODO: implement
  }

  /**
   * Process addational feature from the semi-formal "Bemerkung" note fields
   *
   * Those can trigger certain actions within Civi as mentioned in doc "20131107_Responses_Bemerkungen_1-5"
   */
  public function processAdditionalFeature($note, $contact_id, $record) {
    $config = CRM_Streetimport_Config::singleton();
    $this->logger->logDebug("Contact [{$contact_id}] wants '{$note}'", $record);
    switch ($note) {
       case 'erhält keine Post':
         // Marco: "Posthäkchen setzen, Adresse zurücksetzen, Kürzel 15 + 18 + ZO löschen"
         // TODO:
         break;

       case 'kein Telefonkontakt erwünscht':
         // Marco: "Telefonkanal schließen"
         civicrm_api3('Contact', 'create', array(
          'id' => $contact_id,
          'do_not_phone' => 1));
         $this->logger->logDebug("Setting 'do_not_phone' for contact [{$contact_id}].", $record);
         break;

       case 'keine Kalender senden':
         // Marco: 'Negativleistung "Kalender"'
         $this->addContactToGroup($config->getGPGroup('kein Kalender'), $newsletter_group_id, $record);
         $this->logger->logDebug("Added contact [{$contact_id}] to group 'kein Kalender'.", $record);
         break;

       case 'nur Vereinsmagazin, sonst keine Post':
       case 'nur Vereinsmagazin mit Spendenquittung':
         // Marco: Positivleistung "Nur ACT"
         $this->addContactToGroup($config->getGPGroup('Nur ACT'), $newsletter_group_id, $record);
         $this->logger->logDebug("Added contact [{$contact_id}] to group 'Nur ACT'.", $record);
         break;

       case 'nur Vereinsmagazin mit 1 Mailing':
         // Marco: Positivleistung "Nur ACT"
         $this->addContactToGroup($config->getGPGroup('Nur ACT'), $newsletter_group_id, $record);
         $this->logger->logDebug("Added contact [{$contact_id}] to group 'Nur ACT'.", $record);

         //  + alle Monate bis auf Oktober deaktivieren
         $dm_restrictions = $config->getGPCustomFieldKey('dm_restrictions');
         civicrm_api3('Contact', 'create', array(
            'id'             => $contact_id,
            $dm_restrictions => '1')); // one mailing only
         $this->logger->logDebug("Contact [{$contact_id}]: DM restrictions set to '1'.", $record);
         break;

       case 'möchte keine Incentives':
         // Marco: Negativleistung " Geschenke"
         $this->addContactToGroup($config->getGPGroup('keine Geschenke'), $newsletter_group_id, $record);
         $this->logger->logDebug("Added contact [{$contact_id}] to group 'keine Geschenke'.", $record);
         break;

       case 'möchte keine Postsendungen':
         // Marco: Postkanal schließen
         civicrm_api3('Contact', 'create', array(
          'id' => $contact_id,
          'do_not_mail' => 1));
         $this->logger->logDebug("Setting 'do_not_mail' for contact [{$contact_id}].", $record);
         break;

       case 'möchte max 4 Postsendungen':
         // Marco: Leistung Januar, Februar, März, Mai, Juli, August, September, November deaktivieren
         $dm_restrictions = $config->getGPCustomFieldKey('dm_restrictions');
         civicrm_api3('Contact', 'create', array(
            'id'             => $contact_id,
            $dm_restrictions => '4')); // 4 mailings
         $this->logger->logDebug("Contact [{$contact_id}]: DM restrictions set to '4'.", $record);
         break;

       case 'Postendung nur bei Notfällen':
         // Marco: Im Leistungstool alle Monate rot einfärben
         $dm_restrictions = $config->getGPCustomFieldKey('dm_restrictions');
         civicrm_api3('Contact', 'create', array(
            'id'             => $contact_id,
            $dm_restrictions => '0')); // only emergency mailings
         $this->logger->logDebug("Contact [{$contact_id}]: DM restrictions set to '0'.", $record);
         break;

       case 'hat kein Konto':
       case 'möchte nur Jahresbericht':
         // do nothing according to '20131107_Responses_Bemerkungen_1-5.xlsx'
         $this->logger->logDebug("Nothing to be done for feature '{$note}'", $record);
         break;

       case 'Bankdaten gehören nicht dem Spender':
         //
       case 'Spende wurde übernommen, Daten geändert':
       case 'erhält Post doppelt':
         //


       default:
         return $this->logger->logError("Unkown feature '{$note}' ignored.", $record);
         break;

     }
  }
}
