<?php

namespace Drupal\views_oai_pmh\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Picturae\OaiPmh\Exception\NoRecordsMatchException;
use Picturae\OaiPmh\Interfaces\MetadataFormatType;
use Picturae\OaiPmh\Interfaces\Record;
use Picturae\OaiPmh\Interfaces\Repository as RepositoryInterface;
use Picturae\OaiPmh\Implementation\Repository\Identity;
use Symfony\Component\HttpFoundation\RequestStack;
use Picturae\OaiPmh\Implementation\RecordList;

class Repository implements RepositoryInterface {

  protected $host;

  protected $path;

  protected $scheme;

  protected $port;

  protected $mail;

  protected $siteName;

  protected $records = [];

  public function __construct(ConfigFactoryInterface $config, RequestStack $request) {
    $system_site = $config->get('system.site');

    $this->siteName = $system_site
      ->getOriginal('name', FALSE);
    $this->mail = $system_site->get('mail');

    $currentRequest = $request->getCurrentRequest();

    $this->host = $currentRequest->getHost();
    $this->path = $currentRequest->getPathInfo();
    $this->scheme = $currentRequest->getScheme() . '://';
    $this->port = ':' . $currentRequest->getPort();
  }

  public function getBaseUrl() {
    return $this->scheme . $this->host . $this->port . $this->path;
  }

  public function getGranularity() {
    return 'YYYY-MM-DDThh:mm:ssZ';
  }

  public function identify() {
    $description = new \DOMDocument();
    $oai_identifier = $description->createElement('oai-identifier');

    $oai_identifier->setAttribute('xmlns', 'http://www.openarchives.org/OAI/2.0/oai-identifier');
    $oai_identifier->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
    $oai_identifier->setAttribute('xsi:schemaLocation', 'http://www.openarchives.org/OAI/2.0/oai-identifier http://www.openarchives.org/OAI/2.0/oai-identifier.xsd');
    $description->appendChild($oai_identifier);

    return new Identity(
      $this->siteName,
      new \DateTime(),
      'transient',
      [
        $this->mail,
      ],
      $this->getGranularity(),
      'deflate',
      $description
    );
  }

  public function listSets() {
    throw new NoRecordsMatchException('This repository does not support sets.');
  }

  public function listSetsByToken($token) {
    throw new NoRecordsMatchException('This repository does not support sets.');
  }

  public function getRecord($metadataFormat, $identifier) {
    return $this->records[$identifier];
  }

  public function listRecords($metadataFormat = NULL, \DateTime $from = NULL, \DateTime $until = NULL, $set = NULL) {
    // TODO: enable resubmission token.
    return new RecordList($this->records);
  }

  public function listRecordsByToken($token) {
    // TODO: Implement listRecordsByToken() method.
  }

  public function listMetadataFormats($identifier = NULL) {
    return $this->formats;
  }

  public function setRecords(array $records) {
    $this->records = $records;
  }

  public function setMetadataFormats(array $formats) {
    $this->formats = $formats;
  }

}
