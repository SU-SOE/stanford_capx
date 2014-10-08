<?php
/**
 * @file
 * @author [author] <[email]>
 */

namespace CAPx\Drupal\Organizations;

class Orgs {

  public static $vocabularyMachineName = "capx_organizations";

  /**
   * Prepares the taxonomy vocabulary for saving an org tree
   * @return
   */
  public static function prepareVocabulary() {
    $vocab = Orgs::getVocabulary();

    if (!$vocab) {
      $vocab = new \StdClass();
      $vocab->name = t('CAPx Organizations');
      $vocab->machine_name = $this->getVocabularyMachineName();
      $vocab->description = t("A hierarchy of organization codes and information");
      $vocab->module = "stanford_capx";
      taxonomy_vocabulary_save($vocab);
    }

    if (!$vocab->vid) {
      throw new \Exception("Could not create or find CAPx Organization Taxonomy Vocabulary");
    }
  }

  /**
   * Returns the vocabulary object that orgs use.
   * @return [type] [description]
   */
  public static function getVocabulary() {
    return taxonomy_vocabulary_machine_name_load(Orgs::getVocabularyMachineName());
  }

  /**
   * Returns the top most level term in the vocabulary.
   * @return object The root term
   */
  public static function getRootTerm() {
    $terms = Orgs::getOrganizations();
    return $terms[0];
  }

  /**
   * Checks to see if there are org codes available.
   * Returns True if there are values and false if there are no values
   * @return [type] [description]
   */
  public static function checkOrgs() {
    $vals = Orgs::getOrganizations();
    return !empty($vals);
  }

  /**
   * Returns an array of organizations keyed by org code
   * @return array Organization information
   */
  public static function getOrganizations($parent = 0) {
    $vocab = Orgs::getVocabulary();
    return $vocab ? taxonomy_get_tree($vocab->vid, $parent) : FALSE;
  }

  /**
   * Gets and syncs the Organization data from the CAP API
   * @param  $batch  boolean value on wether or not to use batch api.
   * @return [type] [description]
   */
  public static function syncOrganizations($batch = FALSE) {

    $client = \CAPx\Drupal\Util\CAPxConnection::getAuthenticatedHTTPClient();
    $org = 'AA00';
    $orgInfo = $client->api('org')->getOrg($org);

    if (!$orgInfo) {
      throw new \Exception("Could not get organization information");
    }

    Orgs::prepareVocabulary();

    // No batch
    if (!$batch) {
      $termTree = Orgs::saveOrganizations($orgInfo);
      return;
    }

    // Batching
    $batch = array(
      'operations' => array(
        array('\CAPx\Drupal\Organizations\Orgs::syncOrganizationsBatch', array($orgInfo)),
      ),
      'title' => t('Processing Organization Codes'),
      'init_message' => t('Organization codes sync is starting.'),
      'progress_message' => t('Syncing organization codes in progress.'),
      'error_message' => t('Organization codes could not be imported. Please try again.'),
    );

    batch_set($batch);
    batch_process(drupal_get_destination());
  }

  /**
   * Batch callback for syncOrganizations
   * @param  [type] $orgInfo [description]
   * @return [type]          [description]
   */
  public static function syncOrganizationsBatch($orgInfo, &$context) {
    $sandbox = $context['sandbox'];
    $context['sandbox']['progress'] = isset($sandbox['progress']) ? $sandbox['progress'] : -1;
    $context['sandbox']['max'] = count($orgInfo['children']) - 1;

    // Save the root item.
    if ($context['sandbox']['progress'] == -1) {
      Orgs::saveOrgParents($orgInfo, array(0));
    }

    // What is the top level term.
    $root = Orgs::getRootTerm();

    // Iterate one root child at a time.
    $context['sandbox']['progress']++;
    $limb = $orgInfo['children'][$context['sandbox']['progress']];

    // Save one limb of the tree at a time
    $parents = Orgs::saveOrgParents($limb, array($root->tid));
    Orgs::saveOrgChildren($limb, $parents);

    // Inform the batch engine that we are not finished,
    // and provide an estimation of the completion level we reached.
    if ($context['sandbox']['progress'] !== $context['sandbox']['max']) {
      $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
      $context['message'] = t('Now processing group ' . $context['sandbox']['progress'] . " of " . $context['sandbox']['max']);
    }
  }

  /**
   * Takes an Org tree array response from the server and processes the root
   * organization and each nested tree of children. The organization response
   * from the API looks like:
   *
   * root[orgcodes] = array('AA00');
   * root[children] = array(
   * 0 => array('orgcodes' => array('AABB', 'AACC'), 'children' => array(...))
   * 1 => array('orgcodes' => array('XXYY'), 'children' => array(...))
   * 2 => array('orgcodes' => array('GGHH', 'CCII'), 'children' => array(...))
   * )
   *
   * The org codes on the server are in a hierarchical order when the taxonomy
   * listing will display them alphabetically in their hierarchy.
   *
   * @param  [type] $orgTree [description]
   * @return [type]          [description]
   */
  public static function saveOrganizations($orgTree) {
    $termTree = array();
    $vocab = taxonomy_vocabulary_machine_name_load(Orgs::getVocabularyMachineName());


    $root = new \StdClass();
    $root->name = $orgTree['orgCodes'][0];
    $root->description = strtolower($orgTree['name']);
    $root->vid = $vocab->vid;

    // Check to see if already exists...
    $originalTerms = taxonomy_get_term_by_name($root->name, Orgs::getVocabularyMachineName());
    if (!empty($originalTerms)) {
      $originalTerm = array_shift($originalTerms);
      $root = (object) array_merge((array) $originalTerm, (array) $root);
    }

    taxonomy_term_save($root);

    foreach ($orgTree['children'] as $org) {
      $parents = Orgs::saveOrgParents($org, array($root->tid));
      Orgs::saveOrgChildren($org, array($root->tid));
    }

  }

  /**
   * Save organization terms and return their tids.
   * @param  [type] $org [description]
   * @return [type]      [description]
   */
  public static function saveOrgParents($org, $parents = array()) {

    if (!is_array($org) || !isset($org['orgCodes'])) {
      throw new \Exception("Improper organization passed to saveOrgParents");
    }

    $vocab = taxonomy_vocabulary_machine_name_load(Orgs::getVocabularyMachineName());

    $myParents = array();
    foreach ($org['orgCodes'] as $code) {
      $orgTerm = new \StdClass();
      $orgTerm->name = $code;
      $orgTerm->description = strtolower($org['name']);
      $orgTerm->parent = $parents;
      $orgTerm->vid = $vocab->vid;

      // Check to see if already exists...
      $originalTerms = taxonomy_get_term_by_name($code, Orgs::getVocabularyMachineName());
      if (!empty($originalTerms)) {
        $originalTerm = array_shift($originalTerms);
        $orgTerm = (object) array_merge((array) $originalTerm, (array) $orgTerm);
      }

      taxonomy_term_save($orgTerm);
      $myParents[] = $orgTerm->tid;
    }

    return $myParents;
  }

  /**
   * Save the organization terms children
   * @param  [type] $org     [description]
   * @param  [type] $parents [description]
   * @return [type]          [description]
   */
  public static function saveOrgChildren($org, $parents) {

    if (empty($parents)) {
      throw new \Exception("Invalid parents passed to saveOrgChildren");
    }

    // No children just return
    if (empty($org['children'])) {
      return;
    }

    foreach ($org['children'] as $childOrg) {
      $childParents = Orgs::saveOrgParents($childOrg, $parents);
      Orgs::saveOrgChildren($childOrg, $childParents);
    }
  }

  /**
   * Returns the vocabulary machine name that we decided to use for storing
   * the organization terms.
   * @return [type] [description]
   */
  public static function getVocabularyMachineName() {
    return Orgs::$vocabularyMachineName;
  }

}
