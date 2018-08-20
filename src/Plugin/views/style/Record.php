<?php

namespace Drupal\views_oai_pmh\Plugin\views\style;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\views\Plugin\views\style\StylePluginBase;
use Drupal\views\ResultRow;
use Drupal\views_oai_pmh\Plugin\MetadataPrefixInterface;
use Drupal\views_oai_pmh\Plugin\MetadataPrefixManager;
use Drupal\views_oai_pmh\Service\FormatRowToXml;
use Drupal\views_oai_pmh\Service\Repository;
use Picturae\OaiPmh\Implementation\RecordList;
use Drupal\views_oai_pmh\Service\Provider;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Serializer\Serializer;
use Picturae\OaiPmh\Implementation\Record as OAIRecord;
use Picturae\OaiPmh\Implementation\Record\Header;
use Zend\Diactoros\ServerRequest;
use Picturae\OaiPmh\Implementation\MetadataFormatType;
use Drupal\Core\Cache\Cache;

/**
 * @ingroup views_style_plugins
 *
 * @ViewsStyle(
 *   id = "views_oai_pmh_record",
 *   title = @Translation("OAI-PMH"),
 *   help = @Translation("Displays rows in OAI-PMH records."),
 *   display_types = {"oai_pmh"}
 * )
 */
class Record extends StylePluginBase implements CacheableDependencyInterface {

  protected $usesFields = TRUE;

  protected $usesOptions = TRUE;

  protected $usesRowClass = FALSE;

  protected $usesRowPlugin = FALSE;

  protected $row2Xml;

  protected $prefixManager;

  protected $metadataPrefix = [];

  protected $serializer;

  /**
   * @var \Drupal\views_oai_pmh\Plugin\views\display\OAIPMH
   */
  public $displayHandler;

  protected $repository;

  protected $provider;

  protected $pluginInstances = [];

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('views_oai_pmh.format_row_xml'),
      $container->get('plugin.manager.views_oai_pmh_prefix'),
      $container->get('serializer'),
      $container->get('views_oai_pmh.repository'),
      $container->get('views_oai_pmh.provider')
    );
  }

  /**
   * Record constructor.
   *
   * @param array $configuration
   * @param $plugin_id
   * @param $plugin_definition
   * @param \Drupal\views_oai_pmh\Service\FormatRowToXml $rowToXml
   * @param \Drupal\views_oai_pmh\Plugin\MetadataPrefixManager $prefixManager
   * @param \Symfony\Component\Serializer\Serializer $serializer
   * @param \Drupal\views_oai_pmh\Service\Repository $repository
   * @param \Picturae\OaiPmh\Provider $provider
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, FormatRowToXml $rowToXml, MetadataPrefixManager $prefixManager, Serializer $serializer, Repository $repository, Provider $provider) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->row2Xml = $rowToXml;
    $this->prefixManager = $prefixManager;
    $this->serializer = $serializer;
    $this->repository = $repository;
    $this->provider = $provider;

    foreach ($prefixManager->getDefinitions() as $id => $plugin) {
      $this->metadataPrefix[$id] = $plugin;
    }
  }

  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);

    $handlers = $this->displayHandler->getHandlers('field');
    if (empty($handlers)) {
      $form['error_markup'] = array(
        '#markup' => '<div class="error messages">' . $this->t('You need at least one field before you can configure your table settings') . '</div>',
      );
      return;
    }

    $formats = [];
    foreach ($this->metadataPrefix as $prefix_id => $prefix) {
      $formats[$prefix_id] = $prefix['label'];
    }

    $form['enabled_formats'] = array(
      '#type' => 'checkboxes',
      '#title' => t('OAI-PMH metadata formats'),
      '#description' => t('Select the metadata format(s) that you wish to publish. Note that the Dublin Core format must remain enabled as it is required by the OAI-PMH standard.'),
      '#default_value' => $this->options['enabled_formats'],
      '#options' => $formats,
    );

    $form['metadata_prefix'] = array(
      '#type' => 'fieldset',
      '#title' => t('Metadata prefixes'),
    );

    $field_labels = $this->displayHandler->getFieldLabels();
    foreach ($this->metadataPrefix as $prefix_id => $prefix) {
      $form['metadata_prefix'][$prefix_id] = array(
        '#type' => 'textfield',
        '#title' => $prefix['label'],
        '#default_value' => $this->options['metadata_prefix'][$prefix_id] ? $this->options['metadata_prefix'][$prefix_id] : $prefix['prefix'],
        '#required' => TRUE,
        '#size' => 16,
        '#maxlength' => 32,
      );
      $form['field_mappings'][$prefix_id] = array(
        '#type' => 'fieldset',
        '#title' => t('Field mappings for <em>@format</em>', array('@format' => $prefix['label'])),
        '#theme' => 'views_oai_pmh_field_mappings_form',
        '#states' => array(
          'visible' => array(
            ':input[name="style_options[enabled_formats][' . $prefix_id . ']"]' => array('checked' => TRUE),
          ),
        ),
      );

      $prefixPlugin = $this->getInstancePlugin($prefix_id);
      foreach ($this->displayHandler->getOption('fields') as $field_name => $field) {
        $form['field_mappings'][$prefix_id][$field_name] = array(
          '#type' => 'select',
          '#options' => $prefixPlugin->getElements(),
          '#default_value' => !empty($this->options['field_mappings'][$prefix_id][$field_name]) ? $this->options['field_mappings'][$prefix_id][$field_name] : '',
          '#title' => $field_labels[$field_name],
        );
      }
    }

  }

  /**
   * {@inheritdoc}
   */
  public function render() {
    $rows = $this->getResultRows();

    /** @var \Drupal\views_oai_pmh\Plugin\MetadataPrefixInterface $currentPrefixPlugin */
    $currentPrefixPlugin = $this->prefixManager->createInstance(
      $this->displayHandler->getCurrentMetadataPrefix()
    );

    $records = [];
    foreach ($rows as $row_id => $row) {
      $data = $currentPrefixPlugin->getRootNodeAttributes() + $this->row2Xml->transform($row);

      $xmlDoc = new \DOMDocument();
      $xmlDoc->loadXML($this->serializer->encode($data, 'xml', [
        'xml_root_node_name' => $currentPrefixPlugin->getRootNodeName()
      ]));

      $xml = <<<XML
      <record>
   
      </record>
XML;

      $b= $this->serializer->decode($xml, 'xml');

      $header = new Header($row_id, new \DateTime());
      $records[$row_id] = new OAIRecord($header, $xmlDoc);
    }

    $formats = [];
    foreach ($this->options['enabled_formats'] as $format) {
      $plugin = $this->getInstancePlugin($format);
      $formats[] = new MetadataFormatType(
        $format,
        $plugin->getSchema(),
        $plugin->getNamespace()
      );
    }

    $this->repository->setRecords($records);
    $this->repository->setMetadataFormats($formats);

    return $this->provider;
  }

  protected function getResultRows(): array {
    $rows = [];
    foreach ($this->view->result as $row_id => $row) {
      $this->view->row_index = $row_id;
      $item = $this->populateRow($row);
      $id = $row->_entity->id();

      if (key_exists($id, $rows)) {
        $diff = $this->diff_recursive($item, $rows[$id]);
        $rows[$id] = array_merge_recursive($rows[$id], $diff);
      }
      else {
        $rows[$id] = $item;
      }
    }

    return $rows;
  }

  protected function diff_recursive($array1, $array2) {
    $difference=array();
    foreach($array1 as $key => $value) {
      if(is_array($value) && isset($array2[$key])){ // it's an array and both have the key
        $new_diff = $this->diff_recursive($value, $array2[$key]);
        if( !empty($new_diff) )
          $difference[$key] = $new_diff;
      } else if(is_string($value) && !in_array($value, $array2)) { // the value is a string and it's not in array B
        $difference[$key] = $value;
      } else if(!is_numeric($key) && !array_key_exists($key, $array2)) { // the key is not numberic and is missing from array B
        $difference[$key] = $value;
      }
    }
    return $difference;
  }

  protected function populateRow(ResultRow $row): array {
    $output = [];

    foreach ($this->view->field as $id => $field) {
      $value = $field->getValue($row);
      if (empty($field->options['exclude'])) {
        $output[$this->getFieldKeyAlias($id)] = $value;
      }
    }

    return $output;
  }

  protected function getFieldKeyAlias($id) {
    $fields = $this->options['field_mappings'][$this->displayHandler->getCurrentMetadataPrefix()];

    if (isset($fields) && isset($fields[$id]) && $fields[$id] !== 'none') {
      return $fields[$id];
    }

    return $id;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    return Cache::PERMANENT;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    return ['request_format'];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    return ['views_oai_pmh'];
  }

  protected function getInstancePlugin($plugin_id): MetadataPrefixInterface {
    if (isset($this->pluginInstances[$plugin_id])) {
      return $this->pluginInstances[$plugin_id];
    }

    $this->pluginInstances[$plugin_id] = $this->prefixManager->createInstance($plugin_id);

    return $this->pluginInstances[$plugin_id];
  }

}
