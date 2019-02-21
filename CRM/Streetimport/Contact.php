<?php
/**
 * Class handling the contact processing for streetimport
 *
 * @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org>
 * @date 27 Feb 2017
 * @license AGPL-3.0
 */
class CRM_Streetimport_Contact {

  /**
   * stores the result/logging object
   */
  protected $_logger = NULL;

  /**
   * stores the import record
   */
  private $_record;

  /**
   * CRM_Streetimport_Contact constructor.
   *
   * @param $logger
   * @param $record
   */
  function __construct($logger, $record) {
    $this->_logger = $logger;
    $this->_record = $record;
  }

  /**
   * Method to create contact from import data
   *
   * @param $contactData
   * @return array|string with contact data or $ex->getMessage() when CiviCRM API Exception
   */
  public function createFromImportData($contactData) {
    if (isset($contactData['birth_date'])) {
      $contactData['birth_date'] = $this->formatBirthDate($contactData['birth_date']);
    }
    // create via API
    try {
      $result  = civicrm_api3('Contact', 'create', $contactData);
      $contact = $result['values'][$result['id']];
      return $contact;
    } catch (CiviCRM_API3_Exception $ex) {
      return $ex->getMessage();
    }
  }

  /**
   * Method to create an organization from the contact notes if required
   *
   * @param int $individualId
   * @param string $importNotes
   * @return string $ex->getMessage()
   */
  public function createOrganizationFromImportData($individualId, $importNotes) {
    $config = CRM_Streetimport_Config::singleton();
    // todo check if organization does not exist yet with organisation number
    $notes = new CRM_Streetimport_Notes();
    $organizationData = $notes->getOrganizationDataFromImportData($importNotes);
    if (!empty($organizationData)) {
      // create organization as soon as we have an organization name
      if (isset($organizationData['organization_name']) && !empty($organizationData['organization_name'])) {
        try {
          $organizationParams = array(
            'contact_type' => 'Organization',
            'organization_name' => $organizationData['organization_name'],
          );
          // add organization number if in data
          if (isset($organizationData['organization_number']) && !empty($organizationData['organization_number'])) {
            $organizationNumber = CRM_Streetimport_Utils::formatOrganisationNumber($organizationData['organization_number']);
            $customField = $config->getAivlOrganizationDataCustomFields('aivl_organization_id');
            $organizationParams['custom_'.$customField['id']] = $organizationNumber;
          }
          $result = civicrm_api3('Contact', 'create', $organizationParams);
          $organization = $result['values'][$result['id']];
          // if job title in organization data, update individual with job_title and current employer
          if (isset($organizationData['job_title']) && !empty($organizationData['job_title'])) {
            try {
              civicrm_api3('Contact', 'create', array(
                'id' => $individualId,
                'contact_type' => 'Individual',
                'job_title' => $organizationData['job_title'],
                'employer_id' => $organization['id']
              ));
            } catch (CiviCRM_API3_Exception $ex) {}
          }
          return $organization;
        } catch (CiviCRM_API3_Exception $ex) {
          return $ex->getMessage();
        }
      }
    }
  }

  /**
   * Method to valid contact data
   *
   * @param array $contactData
   * @return string|bool
   */
  public function validateContactData($contactData) {
    $config = CRM_Streetimport_Config::singleton();
    // validate contact type
    if (!isset($contactData['contact_type']) || empty($contactData['contact_type'])) {
      return $config->translate("Contact missing contact_type");
    }
    // validate household name for household
    if ($contactData['contact_type'] == 'Household') {
      if (empty($contactData['household_name'])) {
        return $config->translate("Contact missing household_name");
      }
    }
    // validate first and last name for individual
    if ($contactData['contact_type'] == 'Individual') {
      if (empty($contactData['first_name']) && empty($contactData['last_name'])) {
        return $config->translate("Contact missing first_name and last_name");
      }
      if (!isset($contactData['first_name']) && !isset($contactData['last_name'])) {
        return $config->translate("Contact missing first_name and last_name");
      }
      if (!isset($contactData['first_name']) || empty($contactData['first_name'])) {
        return $config->translate("Donor missing first_name, contact created without first name");
      }
      if (!isset($contactData['last_name']) || empty($contactData['last_name'])) {
        return $config->translate("Donor missing last_name, contact created without first name");
      }
    }
    return TRUE;
  }

  /**
   * Method to correct the birth date when malformatted in csv
   * https://github.com/CiviCooP/be.aivl.streetimport/issues/39
   *
   * @param $birthDate
   * @return string
   */
  private function formatBirthDate($birthDate) {
    try {
      $result = date('d-m-Y', strtotime($birthDate));
      if ($result == '01-01-1970') {
        $this->_logger->logError(CRM_Streetimport_Config::singleton()->translate('Could not format birth date ')
          . $birthDate . CRM_Streetimport_Config::singleton()->translate(', empty birth date assumed. Correct manually!'),
          $this->_record, CRM_Streetimport_Config::singleton()->translate("Create Contact Warning"), "Warning");
        return '';
      }
    }
    catch (Exception $ex) {
      $this->_logger->logError(CRM_Streetimport_Config::singleton()->translate('Could not format birth date ')
        . $birthDate . CRM_Streetimport_Config::singleton()->translate(', empty birth date assumed. Correct manually!'),
        $this->_record, CRM_Streetimport_Config::singleton()->translate("Create Contact Warning"), "Warning");
    }
  }

  /**
   * Method to check if organization settings in the welcome call are consistent with the related streetimport:
   * - if welcome call is not on organization, street recruitment should als not be
   * - if welcome call is on organization, street recruitment should also be
   *
   * @param array $sourceData
   * @return array
   */
  public function checkOrganizationPersonConsistency($sourceData) {
    // no sense in checking if no mandate reference
    if (!isset($sourceData['Mandate Reference'])) {
      return array('valid' => TRUE);
    }
    $config = CRM_Streetimport_Config::singleton();
    // find street recruitment organization
    $query = 'SELECT s.new_org_mandate AS streetRecOrg
    FROM civicrm_activity AS a
    JOIN civicrm_activity_contact AS ac ON a.id = ac.activity_id AND ac.record_type_id = %1
    LEFT JOIN civicrm_value_street_recruitment AS s ON a.id =s.entity_id
    WHERE a.activity_type_id = %2 AND s.new_sdd_mandate = %3
    ORDER BY a.activity_date_time DESC LIMIT 1';
    $queryParams = array(
      1 => array($config->getTargetRecordTypeId(), 'Integer'),
      2 => array($config->getStreetRecruitmentActivityType('value'), 'Integer'),
      3 => array(trim($sourceData['Mandate Reference']), 'String'),
    );
    $streetOrg = CRM_Core_DAO::singleValueQuery($query, $queryParams);
    $acceptedYesValues = $config->getAcceptedYesValues();
    switch ($streetOrg) {
      case 0:
        if (isset($sourceData['Organization Yes/No'])) {
          if (in_array($sourceData['Organization Yes/No'], $acceptedYesValues)) {
            return array(
              'valid' => FALSE,
              'message' => 'Street Recruitment did not mention a company where Welcome Call now does! Please check and fix manually',
            );
          }
        }
        break;

      case 1:
        if (isset($sourceData['Organization Yes/No'])) {
          $acceptedYesValues = $config->getAcceptedYesValues();
          if (!in_array($sourceData['Organization Yes/No'], $acceptedYesValues)) {
            return array(
              'valid' => FALSE,
              'message' => 'Street Recruitment did mention a company where Welcome Call now does not! Please check and fix manually',
            );
          }
        }
        break;
      default:
        return array('valid' => TRUE);
        break;
    }
    return array('valid' => TRUE);
  }
}