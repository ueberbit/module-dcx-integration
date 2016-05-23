<?php

/**
 * @file
 * Contains \Drupal\dcx_integration\JsonClient.
 */

namespace Drupal\dcx_integration;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\dcx_integration\Asset\Article;
use Drupal\dcx_integration\Asset\Image;

/**
 * Class Client.
 *
 * @package Drupal\dcx_integration
 */
class JsonClient implements ClientInterface {
  use StringTranslationTrait;

  /* Instance of the low level PHP JSON API Client provided by digicol.
   *
   * See file api_client/dcx_api_client.class.php
   *
   * @var \DCX_Api_Client
   */
  protected $api_client;

  /**
   * JSON client settings.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;
  /**
   * Constructor.
   */

  /**
   * Publication ID from 'dcx_integration.jsonclientsettings'
   *
   * @var string
   */
  protected $publication_id;

  /**
   * Constructor.
   */
  public function __construct(ConfigFactoryInterface $config_factory, AccountProxyInterface $user, TranslationInterface $string_translation, $override_client_class = NULL) {
    $this->stringTranslation = $string_translation;

    $this->config = $config_factory->get('dcx_integration.jsonclientsettings');

    if (!$override_client_class) {
      $current_user_email = $user->getEmail();
      $site_mail = $config_factory->get("system.site")->get('mail');

      global $base_url;
      $options = [
        'http_headers' => ['X-DCX-Run-As' => $current_user_email],
        'http_useragent' => "DC-X Integration for Drupal (dcx_integration) running on $base_url <$site_mail>",
      ];

      $url = $this->config->get('url');
      $username = $this->config->get('username');
      $password = $this->config->get('password');

      require drupal_get_path('module', 'dcx_integration') . '/api_client/dcx_api_client.class.php';
      $this->api_client = new \DCX_Api_Client($url, $username, $password, $options);
    }
    else {
      $this->api_client = $override_client_class;
    }

    $this->publication_id = $this->config->get('publication');
  }

  /**
   * This is public only for debugging purposes.
   *
   * It's not part of the interface, it should be protected.
   * It really shouldn't be called directly.
   */
  public function getJson($id, $params = NULL) {
    $json = NULL;

    if ($params == NULL) {
      $params = [
        's[pubinfos]' => '*',
        // All fields
        's[fields]' => '*',
        // All properties
        's[properties]' => '*',
        // All files
        's[files]'=> '*',
        // attribute _file_absolute_url of all referenced files in the document
        's[_referenced][dcx:file][s][properties]' => '_file_url_absolute',

        's[_referenced][dcx:pubinfo][s]' => '*',
      ];

      // This filter will not work against the dev-dam server as the respective
      // feature is not deployed on it yet.
      // Hack for the time being. You can enable rights_effective via state
      // variable.
      // @TODO Make default as soon as this works on the dev-dam server.
      if (TRUE == \Drupal::state()->get('rights_effective', FALSE)) {
        $params += [
          's[_rights_effective]' => '*',
          's[_referenced][dcx:rights][s][properties]' => '*',
        ];
      }
    }


    $url = preg_replace('/^dcxapi:/', '', $id);
    $http_status = $this->api_client->getObject($url, $params, $json);

    if (200 !== $http_status) {
      $message = $this->t('Error getting "@url". Status code was @code.', ['@url' => $url, '@code' => $http_status]);
      throw new \Exception($message);
    }

    return $json;
  }

  /**
   * Retrieve a DC-X object with the given id.
   *
   * Emits an HTTP request to the DC-X server and evaluates the response.
   * Depending on the document "Type" (an attribute stored within the fields,
   * not to be confused with the attribute "type") it returns subclasses of
   * BaseAsset which encapsulate a flat array representation of the data
   * retrieved.
   *
   * @param string $id
   *   A dcx object identifier. Something like "dcxapi:document/xyz".
   * @return Drupal\dcx_integration\Asset\BaseAsset
   *   An instance of BaseAsset depending on the retrieved data.
   * @throws \Exception Throws exceptions if anything fails.
   */
  public function getObject($id) {
    $json = $this->getJson($id);

    if (preg_match('/^dcxapi:doc/', $id)) {
      $type = $this->extractData($json, ['fields', 'Type', 0, '_id']);

      switch($type) {
        case "dcxapi:tm_topic/documenttype-story":
          $asset = $this->buildStoryAsset($json);
          break;
        case "dcxapi:tm_topic/documenttype-image":
          // this is the default case as well
        default:
          $asset = $this->buildImageAsset($json);
          break;
      }
        return $asset;
    }
    else {
      throw new \Exception("No handler for URL type $id.");
    }

  }

  /**
   * Builds an Image object from given json array.
   *
   * @return Drupal\dcx_integration\Asset\Image the Image object.
   */
  protected function buildImageAsset($json) {
    $data = [];

    /**
     * Maps an asset attribute to
     *  - the keys of a nested array, or
     *  - to a callback (class + method) and (optional) arguments for further
     *    processing. The callback method called like like this:
     *    call_user_func($callback, $json, $arguments)
     */
    $attribute_map = [
      'id' => ['_id'],
      'filename' => ['fields', 'Filename', 0, 'value'],
      'title' => ['fields', 'Filename', 0, 'value'],
      'url' => [[$this, 'extractUrl'], 'files', 0, '_id'],
      'source' => [[$this, 'joinValues'], 'fields', 'Creator'],
      'copyright' => ['fields', 'CopyrightNotice', 0, 'value'],
      'status' => [[$this, 'computeStatus']],
    ];

    foreach ($attribute_map as $target_key => $source) {
      if (is_array($source[0]) && method_exists($source[0][0], $source[0][1])) {
        $callback = array_shift($source);
        $data[$target_key] = call_user_func($callback, $json, $source);
      }
      elseif (is_array($source)) {
        $data[$target_key] = $this->extractData($json, $source);
      }
    }

    return new Image($data);
  }

  /**
   * Builds an Article object from given json array.
   *
   * @return Drupal\dcx_integration\Asset\Article the Article object.
   */
  protected function buildStoryAsset($json) {
    // @TODO
    throw new \Exception(__METHOD__ . " is not implemented yet");
  }

  /**
   * Descends in the nested array $json following the path of keys given in
   * keys.
   *
   * Returns whatever it finds there.
   *
   * @param array $keys
   * @param array $json
   * @return mixed $value
   */
  protected function extractData($json, $keys) {
    foreach ($keys as $key) {
      $json = $json[$key];
    }
    return $json;
  }

  /**
   * Returns the URL for the file reference described by $keys.
   *
   * This function "knows" where to look for the URL of the file in question.
   *
   * @param array $keys
   * @param array $json
   * @return string URL referenced by the file_id nested in $keys.
   */
  protected function extractUrl($json, $keys) {
    $file_id = $this->extractData($json, $keys);

    $file_url = $this->extractData($json , ['_referenced', 'dcx:file', $file_id, 'properties', '_file_url_absolute']);
    return $file_url;
  }

  /**
   * Computes the (published) status of the image, evaluating the key
   * '_rights_effective'.
   *
   * Searches for a right with the topic_id 'dcxapi:tm_topic/rightsusage-Online'.
   *
   * @param array $json
   * @return bool
   *   The status of the image. True if a right with topic_id
   *   'dcxapi:tm_topic/rightsusage-Online' is present, false otherwise
   */
  protected function computeStatus($json) {
    $rights_ids = $this->extractData($json, ['_rights_effective', 'rightstype-UsagePermitted']);
    foreach (current($rights_ids) as $right) {
      $right_id = $right['_id'];
      $dereferenced_right_id = $json['_referenced']['dcx:rights'][$right_id]['properties']['topic_id']['_id'];
      if ('dcxapi:tm_topic/rightsusage-Online' == $dereferenced_right_id) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Returns a comma separated string of the values of the list referenced by
   * $keys. Use to collect the values of a multi values DC-X field.
   *
   * @param array $keys
   * @param array $json
   * @return string the referenced values as comma separated string
   */
  protected function joinValues($json, $keys) {
    $items = $this->extractData($json, $keys);

    $values = [];
    foreach ($items as $item) {
      $values[] = $item['value'];
    }

    return implode(', ', $values );
  }

  /**
   * {@inheritdoc}
   */
  public function trackUsage($usage_list, $path, $published) {
    $dcx_status = $published?'pubstatus-published':'pubstatus-unpublished';

    $dateTime = new \DateTime();
    $date = $dateTime->format(\DateTime::W3C);

    $dcx_publication = $this->publication_id;

    $known_publications = $this->pubinfoOnPath($path);

    // Delete usage for DC-X Images which are not used anymore
    foreach($known_publications as $dcx_id => $pubinfos ) {
      // If a DC-X ID with a know usage on this $path is not in the usage list
      // anymore
      if (!in_array($dcx_id, $usage_list)) {
        $this->removePubinfos($pubinfos);
      }
    }

    foreach($usage_list as $id) {
      $data = [
        "_type" => "dcx:pubinfo",
        'info' => [
          // While json takes care of the encoding this over the wire
          // we need to make sure that the id is actually encoded in the data,
          // because it's supposed to be called by a http_client.
          'callback_url' => '/dcx-notification?id=' . urlencode($id),
        ],
        "properties" => [
          "doc_id" => [
              "_id" => $id,
              "_type" => "dcx:document"
          ],
          "uri" => $path,
          "date" => $date,
          "status_id" => [
              "_id" => "dcxapi:tm_topic/$dcx_status",
              "_type" => "dcx:tm_topic",
              "value" => "Published"
          ],
          "publication_id" => [
              "_id" => "dcxapi:tm_topic/$dcx_publication",
              "_type" => "dcx:tm_topic",
              "value" => "Bunte"
          ],
          "type_id" => [
              "_id" => "dcxapi:tm_topic/pubtype-article",
              "_type" => "dcx:tm_topic",
              "value" => "Article"
          ],
        ]
      ];

      // Pubinfo is either already known or an empty array
      $pubinfo = isset($known_publications[$id])?$known_publications[$id]:[];

      if (count($pubinfo) > 1) {
        throw new \Exception($this->t('For document %id exists more that one '
          . 'pubinfo refering to %url. This should not be the case and cannot '
          . 'be resolved manually. Please fix this in DC-X.',
          ['%id' => $id, '%url' => $path]));
      }
      if (0 == count($pubinfo)) {
        $http_status = $this->api_client->createObject('pubinfo', [], $data, $response_body);
        if (201 !== $http_status) {
          $message = $this->t('Error creating object %url. Status code was %code.', ['%url' => pubinfo, '%code' => $http_status]);
          throw new \Exception($message);
        }
      }
      else { // 1 == count($pubinfo)
        $pubinfo = current($pubinfo);
        $dcx_api_url = preg_replace('/dcxapi:/', '', $pubinfo['_id']);

        $modcount = $pubinfo['properties']['_modcount'];
        $data['properties']['_modcount'] = $modcount;
        $data['_id'] = $pubinfo['_id'];

        $http_status = $this->api_client->setObject($dcx_api_url, [], $data, $response_body);
        if (200 !== $http_status) {
          $message = $this->t('Error setting object %url. Status code was %code.', ['%url' => $dcx_api_url, '%code' => $http_status]);
          throw new \Exception($message);
        }
      }
    }

  }

  /**
   * {{@inheritdoc}}
   */
  public function archiveArticle($url, $info, $dcx_id) {
    $title = isset($info['title'])?$info['title']:'';
    $body = isset($info['body'])?$info['body']:'';
    $media = isset($info['media'])?$info['media']:[];

    $data = [
      '_type' => 'dcx:document',
      'fields' => [
        'Title' => [
          0 => [
            'value' => $title,
          ],
        ],
        'body' => [
          0 => [
            '_type' => 'xhtml',
            'value' => $body,
          ],
        ],
        'Type'  => [
          [
            "_id" => "dcxapi:tm_topic\/documenttype-story",
            "_type" => "dcx:tm_topic",
            "value" => "Story"
          ]
        ],

      ],
      'properties' => [
        'pool_id' => [
          '_id' => '/dcx/api/pool/native',
          '_type' => 'dcx:pool',
        ],
      ],
    ];

    $i = 0; // We can't be 100% sure that $media has numeric keys in order.
            // Going with the good old counter.
    foreach($media as $item) {
      $i++;

      if  (1 == $i) {
        $tag_group_id = 'primary_image';
      }
      else {
        $tag_group_id = 'image_' . $i . '_' . substr($item['id'], -13);
      }

      $data['fields']['Image'][] = [
        '_type' => 'dcx:taggroup',
        'taggroup_id' => $tag_group_id,
        'fields' => [
          'DocumentRef' => [[
            '_id' => $item['id'],
            '_type' => 'dcx:document',
            'file_variant' => 'master',
            'position' => 1,
          ]],
          'ImageCaption' => [[
            '_type' => 'xhtml',
            'position' => 1,
            'value' => isset($item['caption'])?$item['caption']:'',
          ]],
        ],
      ];
    }

    $response_body = NULL;
    if (NULL != $dcx_id) {
      $json = $this->getJson($dcx_id);
      $modcount = $json['properties']['_modcount'];
      $data['properties']['_modcount'] = $modcount;
      $data['_id'] = '/dcx/api/' . $dcx_id;
      $dcx_api_url = $dcx_id;
      $this->api_client->setObject($dcx_api_url, [], $data, $response_body);
    }
    else {
      $dcx_api_url = 'document';
      $this->api_client->createObject($dcx_api_url, [], $data, $response_body);
    }
    $error = FALSE;

    if (!$response_body) {
      $message = $this->t('The operation yielded no result.');
      $error = TRUE;
    }

    if (!$error && !isset($response_body['_type'])) {
      $message = $this->t('The result operation has no type.');
      $error = TRUE;
    }

    if (!$error && $response_body['_type'] !== 'dcx:success') {
      $message = $response_body['_type'];
      if (isset($response_body['title'])) {
        $message .= ":: " . $response_body['title'];
      }
      $error = TRUE;
    }

    if (!$error && !isset($response_body['location'])) {
      $message = $this->t('The operation was successful, but key location was not found.');
      $error = TRUE;
    }

    if (!$error && preg_match('|/dcx/api/(document/doc.*)|', $response_body['location'], $matches)) {
      $dcx_id = $matches[1];
    }
    else {
      if (!$error) {
      $message = $this->t('The operation was successful, but the location was not parseable.');
      $error = TRUE;
      }
    }

    if ($error) {
      throw new \Exception($this->t('Unable to archive @url, "@message"', ['@url' => $url, '@message' => $message]));
    }

    return $dcx_id;
  }


  /**
   * {{@inheritdoc}}
   */
  public function pubinfoOnPath($path) {
    $json = NULL;
    // @TODO would be nice to filter by publication_id via params to spare us
    // from iterating over bogus results.
    $params = [
      'q[uri]' => $path,
      's[properties]' => '*',
      'q[_limit]' => '*',
    ];

    $http_status = $this->api_client->getObject('pubinfo', $params, $json);
    if (200 !== $http_status) {
      $message = $this->t('Error getting object "@url". Status code was @code.', ['@url' => 'pubinfo', '@code' => $http_status]);
      throw new \Exception($message);
    }

    $pubinfo = [];
    foreach ($json['entries'] as $entry) {
      // ignore entry, if the publication id of this entry does not match ours.
      if ("dcxapi:tm_topic/" . $this->publication_id !== $entry['properties']['publication_id']['_id']) {
        continue;
      }
      $doc_id = $entry['properties']['doc_id']['_id'];
      $id = $entry['_id'];
      $pubinfo[$doc_id][$id] = $entry;
    }

    return $pubinfo;
  }

  /**
   * Deletes the given pubinfo entries
   *
   * This just deletes. It does not make any sanity checks at all.
   *
   * @param array $pubinfos list of pubinfo entries as returned by DC-X.
   *
   * @throws \Exception
   */
  protected function removePubinfos($pubinfos) {
    $response_body = 'we know we wont evaluate this ;)';
    foreach ($pubinfos as $data) {
      $dcx_api_url = $data['_id_url'];
      $http_status = $this->api_client->deleteObject($dcx_api_url, [], $response_body);
      if (204 != $http_status) {
        $message = $this->t('Error deleting object %url. Status code was %code.',
          ['%url' => $dcx_api_url, '%code' => $http_status]);
        throw new \Exception($message);
      }
    }
  }

  /**
   * {{@inheritdoc}}
   */
  public function removeAllUsage($dcx_id) {
    $document = $this->getJson($dcx_id);
    $pubinfos = $document['_referenced']['dcx:pubinfo'];

    foreach ($pubinfos as $key => $pubinfo) {
      if ("dcxapi:tm_topic/" . $this->publication_id !== $pubinfo['properties']['publication_id']['_id']) {
        unset($pubinfos[$key]);
      }
    }
    $this->removePubinfos($pubinfos);
  }
}
