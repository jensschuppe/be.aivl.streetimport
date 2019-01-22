<?php
/**
 * Abstract class bundle common street import functions
 *
 * @author Björn Endres (SYSTOPIA) <endres@systopia.de>
 * @license AGPL-3.0
 */
abstract class CRM_Streetimport_StreetimportRecordHandler extends CRM_Streetimport_RecordHandler {

  // property with company info if we have a company
  public $_genericActivityTplInfo = array();

  /**
   * look up the recruiting organisation
   *
   * From the process description:
   * "For first record of file: check if recruiting organization exists.
   * If it does not, create activity of type ‘Foutieve data import’ with
   * relevant error message in subject and details. No further processing of file possible."
   */
  protected function getRecruitingOrganisation($record) {
    $config = CRM_Streetimport_Config::singleton();
    if (empty($record['Recruiting organization ID'])) {
      $this->logger->abort($config->translate("Recruiting organization ID not given"), $record);
      return NULL;
    }
    $recruiting_organisation = $this->getContact((int) $record['Recruiting organization ID'], $record, true);
    if ($recruiting_organisation==NULL) {
      $this->logger->abort($config->translate("Recruiting organization")." ".$record['Recruiting organization ID']." ".$config->translate("not found"), $record);
      return NULL;
    }
    $this->logger->logDebug($config->translate("Recruiting organization identified as contact")." ".$recruiting_organisation['id'], $record);
    return $recruiting_organisation;
  }

  /**
   * this method will lookup or create the recruiter
   *
   * From the process description:
   * "check if recruiter exists. If not, create recruiter (see CSV description)
   * and create activity of type ‘Foutieve data import’
   * with relevant error message in subject and details"
   *
   * @param array $record
   * @param array $recruiting_organisation
   * @return mixed $recruiter
   */
  protected function processRecruiter($record, $recruiting_organisation) {
    $config = CRM_Streetimport_Config::singleton();
    $external_recruiter_id_field = $config->getRecruiterInformationCustomFields('external_recruiter_id');
    if (empty($external_recruiter_id_field)) {
      $this->logger->abort($config->translate("Custom field 'external_recruiter_id' not found. Please re-install streetimport extension."), $record);
      return;
    }
    $recruiter_id_field = 'custom_' . $external_recruiter_id_field['id'];

    if (!empty($record['Recruiter ID'])) {
      // LOOK UP RECRUITER
      $recruiter_id = $record['Recruiter ID'];
      try {
        // issue 710: retrieve recruiter based on identity tracker
        $identifier = civicrm_api3('Contact', 'identify', array('identifier' => $recruiter_id, 'identifier_type' => 'recruiter_id'));
        $recruiterContactId = $identifier['id'];
        $recruiter = civicrm_api3('Contact', 'getsingle', array('id' => $recruiterContactId));
        $this->logger->logDebug($config->translate("Recruiter with external ID")." ".$recruiter_id." "
            .$config->translate("identified as CiviCRM contact")." ".$recruiter['id'], $record);
        return $recruiter;
      } catch (Exception $e) {
        // not found.
      }
    } else {
      $this->logger->logDebug($config->translate("No column header Recruiter_id found if csv file. New recruiter will be created without recruiter id"), $record);
    }

    // CREATE RECRUITER CONTACT
    $prefixId = $this->getPrefixIdWithImportPrefix(CRM_Utils_Array::value('Recruiter Prefix', $record));
    $genderId = $this->getGenderWithImportPrefix(CRM_Utils_Array::value('Recruiter Prefix', $record));
    $this->logger->logDebug($config->translate("Recruiter not found, creating new one..."), $record);
    // "If the contact is not known, a contact of the contact subtype 'Werver' is to be created"
    $recruiterContactSubType = $config->getRecruiterContactSubType();
    $recruiter_data = array(
      'contact_type'      => 'Individual',
      'contact_sub_type'  => $recruiterContactSubType,
      'first_name'        => CRM_Utils_Array::value('Recruiter First Name', $record),
      'last_name'         => CRM_Utils_Array::value('Recruiter Last Name',  $record),
      'prefix_id'            => $prefixId,
      'gender_id'            => $genderId,
      $recruiter_id_field => CRM_Utils_Array::value('Recruiter ID', $record),
    );

    // "If the first name and last name are empty, the values recruiter_id
    //  "and 'Organization name of recruiting org' will be used as first and last name."
    $testFirst = trim($record['Recruiter First Name']);
    $testLast = trim($record['Recruiter Last Name']);
    if (empty($testFirst) && empty($testLast)) {
      $recruiter_data['first_name'] = $record['Recruiter ID'];
      $recruiter_data['last_name']  = CRM_Utils_Array::value('organization_name', $recruiting_organisation);
      unset($recruiter_data['prefix_id']);
      unset($recruiter_data['gender_id']);
    }
    $recruiter = $this->createContact($recruiter_data, $record);
    if (!$recruiter) {
      $this->logger->abort($config->translate("Recruiter could not be created"), $record);
      return NULL;
    }

    // ..."with a relationship 'Werver' to the recruiting organization."
    $recruiterRelationshipType = $config->getRecruiterRelationshipType();
    $relationshipData = array(
      'contact_id_a' => $recruiting_organisation['id'],
      'contact_id_b' => $recruiter['id'],
      'relationship_type_id' => $recruiterRelationshipType
    );

    $this->createRelationship($relationshipData, $record);
    $this->logger->logDebug($config->translate("Recruiter")." ".$recruiter['id']." ".$config->translate("created"), $record);
    return $recruiter;
  }

  /**
   * will create/lookup the donor along with all relevant information
   *
   * @param array $record
   * @param array $recruitingOrganisation
   * @return array with entity data
   */
  protected function processDonor($record, $recruitingOrganisation) {
    $config = CRM_Streetimport_Config::singleton();
    $donor = $this->getDonorWithExternalId($record['DonorID'], $recruitingOrganisation['id'], $record);
    if (!empty($donor)) {
      // issue #82 if loading type is street recruitment, donor should be new so error if already known
      $loadingType = (int) $record['Loading type'];
      $allowedLoadingTypes = $config->getLoadingTypes();
      if ($allowedLoadingTypes[$loadingType] == "Street Recruitment") {
        $this->logger->logError($config->translate("Donor with ID")." ".$record['DonorID']." ".$config->translate("for recr. org.")
            ." ".$recruitingOrganisation['id']." ".$config->translate("already exists where new donor expected in StreetRecruitment.
            No act. or mandate created"), $record);
        return array();
      } else {
        $donor = $this->updateDonor($record, $donor);
        $this->additionalPhone($record, $donor['contact_id']);
        $this->additionalEmail($record, $donor['contact_id']);
        $this->additionalAddress($record, $donor['contact_id']);
        $donor['mandate_contact_id'] = $donor['id'];
        return $donor;
      }
    }
    $contactData = $this->setDonorData($record);
    $donor = $this->createContact($contactData, $record);
    if (!empty($donor)) {
      $this->setDonorID($donor['id'], $record['DonorID'], $recruitingOrganisation['id'], $record);
      $donor['mandate_contact_id'] = $donor['id'];

      // issue 677 - add organization details if required
      if (in_array(CRM_Utils_Array::value('Organization Yes/No', $record), $config->getAcceptedYesValues())) {
        $organization = new CRM_Streetimport_Contact();
        $newOrganization = $organization->createOrganizationFromImportData($donor['id'], CRM_Utils_Array::value('Notes', $record));
        if (is_array($newOrganization)) {
          if (!isset($newOrganization['id'])) {
            $this->logger->logError($newOrganization, $record, $config->translate("Create Organization for Donor Error"), "Warning");
          }
          else {
            $this->setDonorID($newOrganization['id'], $record['DonorID'], $recruitingOrganisation['id'], $record);
            $donor['mandate_contact_id'] = $newOrganization['id'];
            $this->_genericActivityTplInfo = array(
              'company_id' => $newOrganization['id'],
              'company_name' => $newOrganization['display_name'],
            );
          }
        } else {
          $this->logger->logError($newOrganization, $record, $config->translate("Create Organization for Donor Error"), "Warning");
        }
      }
      else {
        if (isset($this->_genericActivityTplInfo['company_id'])) {
          unset($this->_genericActivityTplInfo['company_id']);
        }
        if (isset($this->_genericActivityTplInfo['company_name'])) {
          unset($this->_genericActivityTplInfo['company_name']);
        }
      }

      // create address
      if (!empty($record['Country'])) {
        $country = CRM_Streetimport_Utils::getCountryByIso($record['Country']);
        if (empty($country)) {
          $countryId = $config->getDefaultCountryId();
        } else {
          $countryId = $country['country_id'];
        }
      } else {
        $countryId = $config->getDefaultCountryId();
      }
      $streetName = trim(CRM_Utils_Array::value('Street Name', $record));
      $streetNumber = (int) trim(CRM_Utils_Array::value('Street Number', $record));
      $streetUnit = trim(CRM_Utils_Array::value('Street Unit', $record));
      $locationTypeId = $config->getLocationTypeId();
      $phonePhoneTypeId = $config->getPhonePhoneTypeId();
      $mobilePhoneTypeId = $config->getMobilePhoneTypeId();
      $otherLocationTypeId = $config->getOtherLocationTypeId();
      $this->createAddress(array(
        'contact_id' => $donor['id'],
        'location_type_id' => $locationTypeId,
        'street_name' => $streetName,
        'street_number' => $streetNumber,
        'street_unit' => $streetUnit,
        'postal_code' => CRM_Utils_Array::value('Postal code', $record),
        'street_address' => $streetName . ' ' . $streetNumber. ' ' . $streetUnit,
        'city' => CRM_Utils_Array::value('City', $record),
        'country_id' => $countryId
      ), $record);

      // create phones
      $this->createPhone(array(
        'contact_id' => $donor['id'],
        'phone_type_id' => $phonePhoneTypeId,
        'location_type_id' => $locationTypeId,
        'phone' => CRM_Utils_Array::value('Telephone1', $record),
      ), $record);
      $this->createPhone(array(
        'contact_id' => $donor['id'],
        'phone_type_id' => $phonePhoneTypeId,
        'location_type_id' => $otherLocationTypeId,
        'phone' => CRM_Utils_Array::value('Telephone2', $record),
      ), $record);
      $this->createPhone(array(
        'contact_id' => $donor['id'],
        'phone_type_id' => $mobilePhoneTypeId,
        'location_type_id' => $locationTypeId,
        'phone' => CRM_Utils_Array::value('Mobile1', $record),
      ), $record);
      $this->createPhone(array(
        'contact_id' => $donor['id'],
        'phone_type_id' => $mobilePhoneTypeId,
        'location_type_id' => $otherLocationTypeId,
        'phone' => CRM_Utils_Array::value('Mobile2', $record),
      ), $record);

      // create email
      $this->createEmail(array(
        'contact_id' => $donor['id'],
        'location_type_id' => $locationTypeId,
        'email' => CRM_Utils_Array::value('Email', $record),
      ), $record);
    }
    return $donor;
  }

  /**
   * Method to set the donor data
   *
   * @param $record
   * @return array
   */
  protected function setDonorData($record) {
    $config = CRM_Streetimport_Config::singleton();
    // create base contact
    $householdPrefixes = $config->getHouseholdPrefixIds();
    $donorData = array();
    $prefixId = $this->getPrefixIdWithImportPrefix(CRM_Utils_Array::value('Prefix', $record));
    $genderId = $this->getGenderWithImportPrefix(CRM_Utils_Array::value('Prefix', $record));
    if (in_array($record['Prefix'], $householdPrefixes)) {
      $donorData['contact_type']      = 'Household';
      $donorData['household_name']    = CRM_Utils_Array::value('Last Name',  $record);
    } else {
      $donorData['contact_type']      = 'Individual';
      $donorData['first_name']        = CRM_Utils_Array::value('First Name', $record);
      $donorData['last_name']         = CRM_Utils_Array::value('Last Name',  $record);
      $donorData['prefix_id']         = $prefixId;
      $donorData['gender_id']         = $genderId;
      $donorData['birth_date']        = $record['Birth date'];
    }
    return $donorData;
  }

  /**
   * Manages the contact_id <-> donor_id (external) mapping
   *
   * @param int $contactId
   * @param int $donorId
   * @param int $recruitingOrganizationId
   */
  protected function setDonorID($contactId, $donorId, $recruitingOrganizationId, $record) {
    $config = CRM_Streetimport_Config::singleton();
    if (empty($contactId)) {
      $this->logger->logError($config->translate("Cannot set Donor ID").', '.$config->translate("contactId missing"), $record);
    } elseif (empty($donorId)) {
      $this->logger->logError($config->translate("Cannot set Donor ID").', '.$config->translate("donorId missing"), $record);
    } elseif (empty($recruitingOrganizationId)) {
      $this->logger->logError($config->translate("Cannot set Donor ID").', '.$config->translate("recruitingOrganizationId missing"), $record);
    } else {
      $tableName = $config->getExternalDonorIdCustomGroup('table_name');
      $query = 'REPLACE INTO '.$tableName.' SET recruiting_organization_id = %1,
        external_donor_id = %2, entity_id = %3';
      $params = array(
        1 => array($recruitingOrganizationId, 'Positive'),
        2 => array($donorId,                  'String'),
        3 => array($contactId,                'Positive')
      );

      $result = CRM_Core_DAO::executeQuery($query, $params);
    }
  }

  /**
   * Manages the contact_id <-> donor_id (external) mapping
   *
   * @param int $donorId
   * @param int $recruitingOrganizationId
   *
   * @return mixed contact_id or NULL if not found
   */
  protected function getContactForDonorID($donorId, $recruitingOrganizationId, $record) {
	try {
      $contactId = CRM_Streetimport_Utils::getContactIdFromDonorId($donorId, $recruitingOrganizationId);
	}
	catch (Exception $e) {
      $this->logger->logError($e->getMessage(), $record);
      return NULL;
	}

	return $contactId;
  }

  /**
   * will extract the required information for a SEPA mandate
   *
   * @return array with mandate data as provided by the record
   */
  protected function extractMandate($record, $donor_id) {
    $config = CRM_Streetimport_Config::singleton();

    // error if no amount
    $mandateAmount = trim(CRM_Utils_Array::value('Amount', $record));
    if (empty($mandateAmount)) {
      $this->logger->logError($config->translate("No amount in SDD data for donor").": " . $donor_id, $record,
        $config->translate("No amount in SDD Data"), "Error");
      return NULL;
    }

    // error if no mandate reference
    $mandateReference = trim(CRM_Utils_Array::value('Mandate Reference', $record));
    if (empty($mandateReference)) {
      $this->logger->logError($config->translate("No mandate reference in SDD data for donor").": " . $donor_id, $record,
        $config->translate("No mandate reference in SDD Data"), "Error");
      return NULL;
    }

    // check frequency unit
    $frequency_unit = CRM_Utils_Array::value('Frequency Unit', $record);
    if (empty($frequency_unit)) {
      $this->logger->logWarning($config->translate("No SDD specified, no mandate created."), $record);
      return NULL;
    }

    // extract the mandate type from the 'Frequency Unit' field
    $mandate_data = $config->extractSDDtype($frequency_unit);
    if (!$mandate_data) {
      $this->logger->logError($config->translate("Bad mandate specification").": " . CRM_Utils_Array::value('Frequency Unit', $record), $record, "Error");
      return NULL;
    }

    // REMARK 'Frequency Interval' is NOT frequency_interval (see https://github.com/CiviCooP/be.aivl.streetimport/issues/56#issuecomment-119829739)
    $cycle_day_option = (int) CRM_Utils_Array::value('Frequency Interval', $record);
    if ($cycle_day_option == 2) {
      $mandate_data['cycle_day'] = 21;
    } else {
      $mandate_data['cycle_day'] = 7;
    }

    // check if IBAN is given
    $iban = trim(CRM_Utils_Array::value('IBAN', $record));
    if (empty($iban)) {
      $this->logger->logError($config->translate("Record with mandate")." ".$record['Mandate Reference']." "
        .$config->translate("has no IBAN"), $record, $config->translate("No IBAN for mandate"), "Error");
      return NULL;
    }

    // look up BIC if it doesn't exist   // BE62510007547061
    $mandate_data['bank_name'] = CRM_Utils_Array::value('Bank Name', $record);
    $bic  = CRM_Utils_Array::value('Bic',  $record);
    if (empty($bic)) {
      try {
        $result = civicrm_api3('Bic', 'getfromiban', array('iban' => $iban));
        $bic = $result['bic'];
        if (empty($mandate_data['bank_name'])) {
          // set bank name, if not given by file
          if (isset($bic['title'])) {
            $mandate_data['bank_name'] = $bic['title'];
          }
        }
        $this->logger->logMessage("Successfully looked up BIC '$bic' with IBAN '$iban'.", $record);
      } catch (CiviCRM_API3_Exception $ex) {
        $this->logger->logError($config->translate("Record with mandate")." ".$record['Mandate Reference']." "
          .$config->translate("has no BIC, and a lookup with IBAN")." ".$iban." ".$config->translate("failed"),
          $record, $config->translate("No BIC for mandate"), "Info");
        return;
      }
    }

    // get the start date
    $now = strtotime("now");
    $start_date = CRM_Utils_Array::value('Start Date', $record);

    //$start_date_parsed = strtotime($start_date);
    $offset = (int) $config->getOffsetDays();
    $earliest_start_date = strtotime("+$offset days");
    if (empty($start_date)) {
      if (!empty($start_date)) {
        $this->logger->logWarning("Couldn't parse start date '$start_date'. Set to start now.", $record);
      }
      $start_date = $earliest_start_date;
    } elseif ($start_date < $earliest_start_date) {
      $this->logger->logWarning("Given start date is in the past. Set to start now.", $record);
      $start_date = $earliest_start_date;
    }

    // get the signature date
    $signature_date = CRM_Utils_Array::value("Recruitment Date", $record);
    //$signature_date_parsed = strtotime($signature_date);
    if (empty($signature_date)) {
      $this->logger->logWarning("Couldn't parse signature date '$signature_date'. Set to start now.", $record);
      $signature_date = $now;
    }

    // get the end date
    $mandate_data['end_date'] = '';
    $end_date = CRM_Utils_Array::value('End Date', $record);
    //$end_date_parsed = strtotime($end_date);
    if (empty($end_date)) {
      if (!empty($end_date)) {
        $this->logger->logWarning("Couldn't parse start end date '$end_date'.", $record);
      }
    } else {
      $end_date = max($start_date, $end_date);
      $mandate_data['end_date'] = date('YmdHis', $end_date);
    }

    // fill the other required fields
    $mandate_data['contact_id']         = $donor_id;
    $mandate_data['reference']          = CRM_Utils_Array::value('Mandate Reference', $record);
    $mandate_data['amount']             = (float) $this->fixImportedAmount(CRM_Utils_Array::value('Amount', $record));
    $mandate_data['currency']           = 'EUR';
    $mandate_data['start_date']         = date('YmdHis', strtotime($start_date));
    $mandate_data['creation_date']      = date('YmdHis'); // NOW
    $mandate_data['date']               = date('YmdHis', strtotime($signature_date));
    $mandate_data['validation_date']    = date('YmdHis', strtotime($signature_date)); // NOW
    $mandate_data['iban']               = $iban;
    $mandate_data['bic']                = $bic;
    $mandate_data['source']             = $config->translate('Street Recruitment');
    $mandate_data['bank_name']          = CRM_Utils_Array::value('Bank Name', $record);
    $mandate_data['campaign_id']        = $this->getCampaignParameter($record);
    $mandate_data['financial_type_id']  = $config->extractSDDFinancialTypeId($mandate_data);

    // don't set $mandate_data['creditor_id'], use default creditor

    return $mandate_data;
  }


  /**
   * Create CiviSEPA mandate
   */
  protected function createSDDMandate($mandate_data, $record) {
    // verify campaign_id
    if (!empty($mandate_data['campaign_id'])) {
      $mandate_data['campaign_id'] = (int) $mandate_data['campaign_id'];

      $result = civicrm_api3('Campaign', 'getcount', array('id' => $mandate_data['campaign_id']));
      if ($result != 1) {
        $config = CRM_Streetimport_Config::singleton();
        $this->logger->logError($config->translate("Campaign with id").' '.$mandate_data['campaign_id'].' '.$config->translate("could not be uniquely identified"), $record);
        unset($mandate_data['campaign_id']);
      }
    }
    // fraud detection to check if fraud warning needs to be created
    try {
      $result = civicrm_api3('SepaMandate', 'createfull', $mandate_data);
      $mandate = $result['values'][$result['id']];
      $this->detectPossibleMandateFraud($mandate, $record);
      $config = CRM_Streetimport_Config::singleton();
      $this->logger->logDebug($config->translate("SDD mandate")." ".$mandate['id']." ".$config->translate("created, reference is")." ".$mandate['reference'], $record);
      return $mandate;
    } catch (CiviCRM_API3_Exception $ex) {
      $config = CRM_Streetimport_Config::singleton();
      $this->logger->logError($config->translate("Error while trying to create mandate. Error was").": " . $ex->getMessage(), $record, "Create SDD Mandate Error", "Error");
      return NULL;
    }
  }

  /**
   * Method to check for possible fraud with mandate and create fraud warning activity if found
   *
   * @param $mandate
   * @param $record
   */
  private function detectPossibleMandateFraud($mandate, $record) {
    $fraudDetection = new CRM_Streetimport_FraudDetection();
    $otherContacts = $fraudDetection->checkIbanAlreadyUsedForOtherContact($mandate['iban'], $mandate['contact_id']);
    if ($otherContacts) {
      $warningData = $this->extractFraudWarningData($mandate, $record);
      $warningData['warning_message'] = CRM_Streetimport_Config::singleton()->translate(
        'IBAN already used for other contacts').' '.implode(', ', $otherContacts);
      $warningData['other_contacts'] = $otherContacts;
      $fraudDetection->createFraudWarning($warningData);
    }
  }

  /**
   * Method to extract data for fraud warning activity from mandate and csv-record
   *
   * @param $mandate
   * @param $record
   * @return array
   */
  private function extractFraudWarningData($mandate, $record) {
    $result = array(
      'target_id' => $mandate['contact_id'],
    );
    // set contribution or contribution recur id based on entity table
    switch ($mandate['entity_table']) {
      case 'civicrm_contribution':
        $result['contribution_id'] = $mandate['entity_id'];
        break;
      case 'civicrm_contribution_recur':
        $result['contribution_recur_id'] = $mandate['entity_id'];
        break;
    }
    try {
      $recruiter = civicrm_api3('Contact', 'identify', array(
        'identifier' => $record['Recruiter ID'],
        'identifier_type' => 'recruiter_id',
      ));
      $result['recruiter_id'] = $recruiter['id'];
    }
    catch (CiviCRM_API3_Exception $ex) {
      $result['recruiter_id'] = NULL;
    }
    return $result;
  }

  /**
   * This function will make sure, that the donor
   * has a (CiviBanking) bank account entry with the given data
   *
   * @param $mandate_data   mandate entity data
   */
  protected function saveBankAccount($mandate_data, $record) {
    $config = CRM_Streetimport_Config::singleton();
    $type_id_IBAN = (int) CRM_Core_OptionGroup::getValue('civicrm_banking.reference_types', 'IBAN', 'name', 'String', 'id');
    if (empty($type_id_IBAN)) {
      $this->logger->abort("Could't find 'IBAN' reference type. Maybe CiviBanking is not installed?", $record);
      return;
    }

    $account_exists = FALSE;
    try {
      // check the user's bank accounts
      $ba_list = civicrm_api3('BankingAccount', 'get', array('contact_id' => $mandate_data['contact_id']));
      foreach ($ba_list['values'] as $ba_id => $ba) {
        $ref_query = array('ba_id' => $ba['id'], 'reference_type_id' => $type_id_IBAN);
        $ba_ref_list = civicrm_api3('BankingAccountReference', 'get', $ref_query);
        foreach ($ba_ref_list['values'] as $ba_ref_id => $ba_ref) {
          if ($ba_ref['reference'] == $mandate_data['iban']) {
            $account_exists = TRUE;
            break 2;
          }
        }
      }

      if ($account_exists) {
        $this->logger->logDebug($config->translate("Bank account")." ".$mandate_data['iban']." "
            .$config->translate("already exists with contact")." ".$mandate_data['contact_id'], $record);
      } else {
        // create bank account (using BAOs)
        $baExtraSource = $config->translate('Street Recruitment');
        $baDescription = $config->translate('Private Account');
        $ba_extra = array(
          'BIC'     => $mandate_data['bic'],
          'country' => substr($mandate_data['iban'], 0, 2),
          'source'  => $baExtraSource,
        );
        if (!empty($mandate_data['bank_name'])) {
          $ba_extra['bank_name'] = $mandate_data['bank_name'];
        }

        $ba = civicrm_api3('BankingAccount', 'create', array(
          'contact_id'   => $mandate_data['contact_id'],
          'description'  => $baDescription,
          'created_date' => date('YmdHis'),
          'data_raw'     => '{}',
          'data_parsed'  => json_encode($ba_extra),
          ));

        // add a reference
        civicrm_api3('BankingAccountReference', 'create', array(
          'reference'         => $mandate_data['iban'],
          'reference_type_id' => $type_id_IBAN,
          'ba_id'             => $ba['id'],
          ));

        $this->logger->logDebug($config->translate("Bank account")." ".$mandate_data['iban']." ".$config->translate("created for contact")
            ." ".$mandate_data['contact_id'], $record);
      }
    } catch (Exception $ex) {
      $this->logger->logError($config->translate("An error occurred while saving the bank account").": " . $ex->getMessage(), $record, "Error");
    }
  }


  /**
   * Method to get contact data with donor Id
   *
   * @param int $donorId
   * @param int $recruitingOrganizationId
   * @return array
   * @access public
   */
  public function getDonorWithExternalId($donorId, $recruitingOrganizationId, $record) {
    if (empty($donorId)) {
      return array();
    }
    $contactId = $this->getContactForDonorID($donorId, $recruitingOrganizationId, $record);
    if (empty($contactId)) {
      return array();
    }
    return $this->getContact($contactId, $record);
  }

  /**
   * Method to set the areas of interest
   *
   * @param $sourceAreasInterest
   * @return null|string
   * @access public
   */
  public function getAreasOfInterest($sourceAreasInterest) {
    $areasOfInterest = null;
    if (!empty($sourceAreasInterest)) {
      $config = CRM_Streetimport_Config::singleton();
      $tempAreas = array();
      $optionGroupId = $config->getAreasOfInterestOptionGroup();
      $parts = explode('/', $sourceAreasInterest);
      foreach ($parts as $part) {
        $params = array(
          'option_group_id' => $optionGroupId,
          'label' => trim($part),
          'return' => 'value');
        try {
          $tempAreas[] = civicrm_api3('OptionValue', 'Getvalue', $params);
        } catch (CiviCRM_API3_Exception $ex) {
          $createParams = array(
            'option_group_id' => $optionGroupId,
            'label' => trim($part));
          try {
            $optionValue = civicrm_api3('OptionValue', 'Create', $createParams);
            if (isset($optionValue['values']['value'])) {
              $tempAreas[] = $optionValue['values']['value'];
            }
          } catch (CiviCRM_API3_Exception $ex) {}
        }
      }
    }
    if (!empty($tempAreas)) {
      $areasOfInterest = CRM_Core_DAO::VALUE_SEPARATOR.implode(CRM_Core_DAO::VALUE_SEPARATOR, $tempAreas).CRM_Core_DAO::VALUE_SEPARATOR;
    }
    return $areasOfInterest;
  }

  /**
   * Method to retrieve the frequency unit value with a label
   *
   * @param $sourceFrequencyUnit
   * @return array|null
   */
  public function getFrequencyUnit($sourceFrequencyUnit) {
    $config = CRM_Streetimport_Config::singleton();
    $optionGroupId = $config->getFrequencyUnitOptionGroup();
    $params = array(
      'option_group_id' => $optionGroupId,
      'label' => strtolower($sourceFrequencyUnit),
      'return' => 'value');
    try {
      $frequencyUnit = civicrm_api3('OptionValue', 'Getvalue', $params);
    } catch (CiviCRM_API3_Exception $ex) {
      $frequencyUnit = null;
    }
    return $frequencyUnit;
  }

  /**
   * extract the campaign ID from the record and returns
   * it as a parameter suitable for the API.
   * That means in particular, that it is an integer, however,
   * the API expects '' instead of '0'.
   */
  public function getCampaignParameter(&$record) {
    $campaign_id = (int) CRM_Utils_Array::value("Campaign ID", $record);
    if ($campaign_id) {
      try {
        civicrm_api3('Campaign', 'Getsingle', array('id' => $campaign_id));
      } catch (CiviCRM_API3_Exception $ex) {
        $config = CRM_Streetimport_Config::singleton();
        $this->logger->logError('Campaign ID '.$campaign_id.' '.$config->translate('not found, no campaign used for donor')
          .' '.$record['DonorID'], $record, $config->translate('Campaign ID not found'));
        $record['Campaign ID'] = '';
        return '';
      }
      return $campaign_id;
    } else {
      return '';
    }
  }

  /**
   * Method to retrieve the campaign title with the campaign id
   * (GitHub issue 63)
   *
   * @param int $campaignId
   * @return string|bool
   * @access public
   */
  public function getCampaignTitle($campaignId) {
    $params = array(
      'id' => $campaignId,
      'return' => 'title');
    try {
      return civicrm_api3('Campaign', 'Getvalue', $params);
    } catch (CiviCRM_API3_Exception $ex) {
      return FALSE;
    }
  }

  /**
   * Method to concat subject for activity with campaign title
   * (GitHub issue 63)
   *
   * @param null $subject
   * @param null $campaignId
   * @return null|string
   */
  public function concatActivitySubject($subject = null, $campaignId = null) {
    $config = CRM_Streetimport_Config::singleton();
    if (empty($campaignId) || empty($subject)) {
      return $subject;
    } else {
      $campaignTitle = $this->getCampaignTitle($campaignId);
      $subject = $config->translate($subject);
      if ($campaignTitle) {
        return $subject." - ".$config->translate("Campaign").": ".$campaignTitle;
      }
    }
  }

  /**
   * Method to add custom data for activity
   *
   * @param int $activityId
   * @param string $tableName
   * @param array $data array holding key/value pairs (expecting column names in key and array with type and value in value)
   * @return bool
   * @access public
   */
  public function createActivityCustomData($activityId, $tableName, $data, $record) {
    $config = CRM_Streetimport_Config::singleton();
    if (CRM_Core_DAO::checkTableExists($tableName) == FALSE) {
      $this->logger->logError($config->translate('No custom data for activity created, could not find custom table').' '.$tableName, $record);
      return FALSE;
    }
    if (empty($activityId)) {
      $this->logger->logError('No custom data for activity created', $record);
      return FALSE;
    }
    $setValues = array();
    $setParams = array();
    $setValues[1] = 'entity_id = %1';
    $setParams[1] = array($activityId, 'Integer');
    $index = 2;

    foreach ($data as $key => $valueArray) {
      if (!empty($valueArray['value'])) {
        $setValues[] = $key . ' = %' . $index;
        $setParams[$index] = array($valueArray['value'], $valueArray['type']);
        $index++;
      }
    }
    if (empty($setValues)) {
      $this->logger->logError('No custom data for activity created, no data', $record);
      return FALSE;
    }
    $query = 'INSERT INTO '.$tableName.' SET '.implode(', ', $setValues);
    try {
      CRM_Core_DAO::executeQuery($query, $setParams);
      return TRUE;
    } catch (Exception $ex) {
      $this->logger->logError($config->translate('No custom data for activity created'), $record);
      return FALSE;
    }
  }

  /**
   * Method to check if donor needs to be updated with new data, and execute update
   *
   * @param array $record
   * @param array $currentDonor
   * @return array
   * @access public
   */
  public function updateDonor($record, $currentDonor) {
    $config = CRM_Streetimport_Config::singleton();
    if ($this->donorNeedsToBeUpdated($record, $currentDonor) == TRUE) {
      try {
        civicrm_api3('Contact', 'Create', $this->setUpdateDonorParams($record, $currentDonor));
      } catch (CiviCRM_API3_Exception $ex) {
        $this->logger->logError($config->translate("Could not update contact")." ".$currentDonor['id']);
      }
    }
    return $this->getContact($currentDonor['id'], $record);
  }

  /**
   * Method to check if the donor needs to be updated
   *
   * @param $record
   * @param $donor
   * @return bool
   * @access public
   */
  public function donorNeedsToBeUpdated($record, $donor) {
    if ($record['First Name'] != $donor['first_name'] || $record['Last Name'] != $donor['last_name']) {
      return TRUE;
    }
    $donorBirthDate = date('Ymd', strtotime($donor['birth_date']));
    $recordBirthDate = date('Ymd', strtotime($record['Birth date']));
    if ($donorBirthDate != $recordBirthDate) {
      return TRUE;
    }
    $donorPrefix = $this->getImportPrefixWithPrefixId($donor['prefix_id']);
    if ($donorPrefix != $record['Prefix']) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Method to set the params for update based on current donor and incoming record
   *
   * @param array $record
   * @param array $donor
   * @return array $params
   */
  public function setUpdateDonorParams($record, $donor) {
    $params = array();
    if ($record['First Name'] != $donor['first_name']) {
      $params['first_name'] = $record['First Name'];
    }
    if ($record['Last Name'] != $donor['last_name']) {
      $params['last_name'] = $record['Last Name'];
    }
    $donorBirthDate = date('Ymd', strtotime($donor['birth_date']));
    $recordBirthDate = date('Ymd', strtotime($record['Birth date']));
    if ($recordBirthDate != $donorBirthDate) {
      $params['birth_date'] = $recordBirthDate;
    }
    $donorPrefix = $this->getImportPrefixWithPrefixId($donor['prefix_id']);
    if ($record['Prefix'] != $donorPrefix) {
      $params['prefix_id'] = $this->getPrefixIdWithImportPrefix(strtolower($record['Prefix']));
      $params['gender_id'] = $this->getGenderWithImportPrefix($record['Prefix']);
    }
    if (!empty($params)) {
      $params['id'] = $donor['id'];
    }
    return $params;
  }

  /**
   * Method to add additional phones to contact if they do not exist yet
   *
   * @param array $record
   * @param int $contactId
   */
  public function additionalPhone($record, $contactId) {
    $config = CRM_Streetimport_Config::singleton();
    $locationTypeId = $config->getLocationTypeId();
    $phoneTypeId = $config->getPhonePhoneTypeId();
    $mobileTypeId = $config->getMobilePhoneTypeId();
    $phoneArray = array(CRM_Utils_Array::value('Telephone1', $record), CRM_Utils_Array::value('Telephone2', $record));
    foreach ($phoneArray as $phone) {
      if (!empty($phone)) {
        $params = array(
          'contact_id' => $contactId,
          'phone_numeric' => $phone);
        try {
          $phoneCount = civicrm_api3('Phone', 'Getcount', $params);
          if ($phoneCount == 0) {
            $this->createPhone(array(
              'contact_id'       => $contactId,
              'phone_type_id'    => $phoneTypeId,
              'location_type_id' => $locationTypeId,
              'phone'            => $phone
            ), $record);
          }
        } catch (CiviCRM_API3_Exception $ex) {
          $this->createPhone(array(
            'contact_id' => $contactId,
            'phone_type_id' => $phoneTypeId,
            'location_type_id' => $locationTypeId,
            'phone' => $phone
          ), $record);
        }
      }
    }
    $mobileArray = array($record['Mobile1'], $record['Mobile2']);
    foreach ($mobileArray as $mobile) {
      if (!empty($mobile)) {
        $params = array(
          'contact_id' => $contactId,
          'phone_numeric' => $mobile);
        try {
          $phoneCount = civicrm_api3('Phone', 'Getcount', $params);
          if ($phoneCount == 0) {
            $this->createPhone(array(
              'contact_id'       => $contactId,
              'phone_type_id'    => $mobileTypeId,
              'location_type_id' => $locationTypeId,
              'phone'            => $mobile
            ), $record);
          }
        } catch (CiviCRM_API3_Exception $ex) {
          $this->createPhone(array(
            'contact_id' => $contactId,
            'phone_type_id' => $mobileTypeId,
            'location_type_id' => $locationTypeId,
            'phone' => $mobile
          ), $record);
        }
      }
    }
  }

  /**
   * Method to add additional emails to contact if they do not exist yet
   *
   * @param array $record
   * @param int $contactId
   */
  public function additionalEmail($record, $contactId) {
    $config = CRM_Streetimport_Config::singleton();
    $locationTypeId = $config->getLocationTypeId();
    $emailFromRecord = CRM_Utils_Array::value('Email', $record);
    if (!empty($emailFromRecord) && !CRM_Streetimport_Utils::isFakeEmailAddress($emailFromRecord)) {
      $params = array(
        'contact_id' => $contactId,
        'email' => $emailFromRecord);
      try {
        $emailCount = civicrm_api3('Email', 'Getcount', $params);
        if ($emailCount == 0) {
          $this->createEmail(array(
            'contact_id'       => $contactId,
            'location_type_id' => $locationTypeId,
            'email'            => CRM_Utils_Array::value('Email', $record)
          ), $record);
        }
      } catch (CiviCRM_API3_Exception $ex) {
        $this->createEmail(array(
          'contact_id'       => $contactId,
          'location_type_id' => $locationTypeId,
          'email'            => CRM_Utils_Array::value('Email', $record),
        ), $record);
      }
    }
  }

  /**
   * Method to add additional addresses to contact if they do not exist yet
   *
   * @param array $record
   * @param int $contactId
   */
  public function additionalAddress($record, $contactId) {
    $config = CRM_Streetimport_Config::singleton();
    $countryFromRecord = CRM_Utils_Array::value('Country', $record);
    if (!empty($countryFromRecord)) {
      $country = CRM_Streetimport_Utils::getCountryByIso($countryFromRecord);
      if (empty($country)) {
        $countryId = $config->getDefaultCountryId();
      } else {
        $countryId = $country['country_id'];
      }
    } else {
      $countryId = $config->getDefaultCountryId();
    }
    $params = array(
      'contact_id' => $contactId,
      'street_name' => CRM_Utils_Array::value('Street Name', $record),
      'street_number' => CRM_Utils_Array::value('Street Number', $record),
      'postal_code' => CRM_Utils_Array::value('Postal code', $record),
      'city' => CRM_Utils_Array::value('City', $record),
      'country_id' => $countryId
    );
    $streetUnitFromRecord = CRM_Utils_Array::value('Street Unit', $record);
    $streetNameFromRecord = CRM_Utils_Array::value('Street Name', $record);
    $streetNumberFromRecord = CRM_Utils_Array::value('Street Number', $record);
    if (!empty($streetUnitFromRecord)) {
      $params['street_unit'] = $streetUnitFromRecord;
    }
    $locationTypeId = $config->getLocationTypeId();
    $streetAddress = trim($streetNameFromRecord.' '.$streetNumberFromRecord.' '.$streetUnitFromRecord);
    try {
      $addressCount = civicrm_api3('Address', 'Getcount', $params);
      if ($addressCount == 0) {
        $this->createAddress(array(
          'contact_id'       => $contactId,
          'location_type_id' => $locationTypeId,
          'street_name'      => CRM_Utils_Array::value('Street Name', $record),
          'street_number'    => (int) CRM_Utils_Array::value('Street Number', $record),
          'street_unit'      => CRM_Utils_Array::value('Street Unit', $record),
          'postal_code'      => CRM_Utils_Array::value('Postal code', $record),
          'street_address'   => $streetAddress,
          'city'             => CRM_Utils_Array::value('City', $record),
          'is_primary'       => 1,
          'country_id'       => $countryId
        ), $record);
      }
    } catch (CiviCRM_API3_Exception $ex) {
      $this->createAddress(array(
        'contact_id'       => $contactId,
        'location_type_id' => $locationTypeId,
        'street_name'      => CRM_Utils_Array::value('Street Name', $record),
        'street_number'    => (int) CRM_Utils_Array::value('Street Number', $record),
        'street_unit'      => CRM_Utils_Array::value('Street Unit', $record),
        'postal_code'      => CRM_Utils_Array::value('Postal code', $record),
        'street_address'   => $streetAddress,
        'is_primary'       => 1,
        'country_id'       => $countryId
      ), $record);
    }
  }

  /**
   * Method to get the prefix value with import prefix
   *
   * @param string $importPrefix
   * @return array|bool
   * @access public
   */
  public function getPrefixIdWithImportPrefix($importPrefix) {
    if (!empty($importPrefix)) {
      $prefixRule = new CRM_Streetimport_PrefixRule();
      $prefix = $prefixRule->getWithImportPrefix($importPrefix);
      if (isset($prefix['civicrm_prefix'])) {
        return $prefix['civicrm_prefix'];
      }
    }
    return FALSE;
  }

  /**
   * Method to get the gender id with import prefix
   *
   * @param string $importPrefix
   * @return array|bool
   * @access public
   */
  public function getGenderWithImportPrefix($importPrefix) {
    if (!empty($importPrefix)) {
      $prefixRule = new CRM_Streetimport_PrefixRule();
      $prefix = $prefixRule->getWithImportPrefix($importPrefix);
      if (isset($prefix['gender'])) {
        return $prefix['gender'];
      }
    }
    return FALSE;
  }


  /**
   * Method to get the prefix label with value
   *
   * @param int $prefixId
   * @return array|bool
   * @access public
   */
  public function getImportPrefixWithPrefixId($prefixId) {
    if (!empty($importPrefix)) {
      $prefixRule = new CRM_Streetimport_PrefixRule();
      $prefix = $prefixRule->getWithCiviCRMPrefix($prefixId);
      if (Isset($prefix['import_prefix'])) {
        return $prefix['import_prefix'];
      }
    }
  }

  /**
   * Function to change the imported amount if digital comma is used rather than dot
   * and round it to 2 decimals (issue #81)
   *
   * @param $importedAmount
   * @return string
   * @access public
   * @static
   */
  public function fixImportedAmount($importedAmount) {
    $amountString = (string) $importedAmount;
    $amountString = str_replace(',', '.', $amountString);
    $amount = (float) $amountString;
    return round($amount, 2);
  }

  /**
   * Method to check if the donor's latest street import activity allows the current one coming in
   * - if current coming in is street recruitment, either no activity at all or latest is welcome call is OK
   * - if current coming in is welcome call, latest activity has to be street recruitment
   *
   * @param $donor
   * @param $type
   * @return string $errorMessage
   * @access protected
   */
  public function donorAlreadyHasIncomingActivity($donor, $type) {
    $config = CRM_Streetimport_Config::singleton();
    $streetRecruitmentTypeId = $config->getStreetRecruitmentActivityType('value');
    $welcomeCallTypeId = $config->getWelcomeCallActivityType('value');
    // get the latest streetimport activity for the contact ordered by create_date if it exists or
    // by activity_date_time for earlier versions
    if (CRM_Core_DAO::checkFieldExists('civicrm_activity', 'created_date')) {
      $query = "SELECT act.activity_type_id
      FROM civicrm_activity_contact AS actcont
      JOIN civicrm_activity AS act ON actcont.activity_id = act.id
      WHERE actcont.record_type_id = %1 AND actcont.contact_id = %2 AND act.is_current_revision = %3 
      AND act.is_test = %4 AND act.is_deleted = %4 AND act.activity_type_id IN (%5, %6)
      ORDER BY act.created_date DESC LIMIT 1";
    }
    else {
      $query = "SELECT act.activity_type_id
      FROM civicrm_activity_contact AS actcont
      JOIN civicrm_activity AS act ON actcont.activity_id = act.id
      WHERE actcont.record_type_id = %1 AND actcont.contact_id = %2 AND act.is_current_revision = %3 
      AND act.is_test = %4 AND act.is_deleted = %4 AND act.activity_type_id IN (%5, %6)
      ORDER BY act.activity_date_time DESC LIMIT 1";
    }
    $params = array(
      1 => array(3, 'Integer'),
      2 => array($donor['id'], 'Integer'),
      3 => array(1, 'Integer'),
      4 => array(0, 'Integer'),
      5 => array($streetRecruitmentTypeId, 'Integer'),
      6 => array($welcomeCallTypeId, 'Integer'),
    );
    $latestActivityTypeId = CRM_Core_DAO::singleValueQuery($query, $params);
    // check depending on incoming type
    switch ($type) {
      // if street recruitment, either no activity or latest is welcome call then it is fine else problem
      case 'StreetRecruitment':
        if ($latestActivityTypeId && $latestActivityTypeId == $welcomeCallTypeId) {
          return 'Donor already has a street recruitment as its latest street import activity';
        }
        break;
        // if welcome call, latest activity has to be streetrecruitment else problem
      case 'WelcomeCall':
        if (!$latestActivityTypeId) {
          return 'Donor has no street recruitment when trying to add a welcome call';
        }
        else {
          if ($latestActivityTypeId == $welcomeCallTypeId) {
            return 'Donor already has a welcome call as its latest street import activity';
          }
        }
        break;
    }
    return NULL;
  }

}

