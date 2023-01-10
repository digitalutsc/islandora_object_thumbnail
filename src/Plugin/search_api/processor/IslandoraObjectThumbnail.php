<?php

namespace Drupal\islandora_object_thumbnail\Plugin\search_api\processor;

use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\media\Entity\Media;
use Drupal\file\Entity\File;
use Drupal\image\Entity\ImageStyle;

/**
 * Adds the item's view count to the indexed data.
 *
 * @SearchApiProcessor(
 *   id = "islandora_object_thumbnail",
 *   label = @Translation("Islandora Object Thumbnail"),
 *   description = @Translation("Add index for the thumbnail of Islandora Ojbect."),
 *   stages = {
 *     "add_properties" = 0,
 *   },
 *   locked = true,
 *   hidden = false,
 * )
 */
class IslandoraObjectThumbnail extends ProcessorPluginBase {

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    /** @var static $processor */
    $processor = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    return $processor;
  }

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(DatasourceInterface $datasource = NULL) {
    $properties = [];

    if (!$datasource) {
      $definition = [
        'label' => $this->t('Islandora Object Thumbnail'),
        'description' => $this->t('Thumbnail of Islandora Object to be indexed to Solr'),
        'type' => 'string',
        'processor_id' => $this->getPluginId(),
      ];
      $properties['search_api_islandora_object_thumbnail'] = new ProcessorProperty($definition);
    }

    return $properties;
  }

  public function is_404($url) {
    $handle = curl_init($url);
    curl_setopt($handle,  CURLOPT_RETURNTRANSFER, TRUE);

    /* Get the HTML or whatever is linked in $url. */
    $response = curl_exec($handle);

    /* Check for 404 (file not found). */
    $httpCode = curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);

    /* If the document has loaded successfully without any redirection or error */
    if ($httpCode >= 200 && $httpCode < 300) {
      return false;
    } else {
      return true;
    }
  }
  /**
   * {@inheritdoc}
   */
  public function addFieldValues(ItemInterface $item) {
    $datasourceId = $item->getDatasourceId();
    if ($datasourceId == 'entity:node') {
      try {
        global $base_url;
        // process to get the thumbnail
        $node = $item->getOriginalObject()->getValue();

        // send the rest request to view islandora_object_thumbnail
        $uri = "$base_url/islandora_object/". $node->id() . '/thumbnail';

        // process the restons
        $request = \Drupal::httpClient()->get($uri);
        $thumbnails = json_decode($request->getBody());

        // loop but assume each media only has ONLY ONE thumbnail
        foreach ($thumbnails as $thumbnail) {
          $thumbnail_url = $base_url . $thumbnail->thumbnail__target_id;

          // set value to index to Solr
          $fields = $this->getFieldsHelper()->filterForPropertyPath($item->getFields(), NULL,
            'search_api_islandora_object_thumbnail');
          foreach ($fields as $field) {
            $field->addValue($thumbnail_url);
          }
        }
      } catch(\Exception $e) {
        error_log(print_r($e->getMessage(), TRUE), 0);
      }
    }
  }

  /**
   * Unused
   * @param $node
   * @return void
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getThumbnailByField($node, ItemInterface $item) {
    // check the node is islandora_object with media field
    if ($node->hasField('field_islandora_object_media')) {

      // Get the referenced media
      $referred_medias = $node->get('field_islandora_object_media');
      // loop through each media
      foreach ($referred_medias as $refferred_media) {
        $iterator = Media::load($refferred_media->getValue()['target_id']);

        $has_thumbnail = false;
        // check if this media has thumbnail assigned by Media Use
        if ((get_class($iterator) === "Drupal\media\Entity\Media") &&
          $iterator->hasField("field_media_use")) {
          $media_uses = $iterator->get("field_media_use")->referencedEntities();
          // loop through media uses to check if there is Thumbnail
          foreach ($media_uses as $media_use) {
            if ($media_use->label() === "Thumbnail Image") {
              $has_thumbnail = true;

              // break since we assume that one media has ONLY one thumbnail
              break;
            }
          }
        }

        // get the thumbnail if there is thumbnail assigned based on the Media Use
        if ($has_thumbnail) {
          $file_uri = null;
          $generic_thumbnail = \Drupal::service('file_url_generator')->generateAbsoluteString("public://media-icons/generic/generic.png");;
          if ($iterator->hasField('field_media_image')) {
            $file_uri = $iterator->field_media_image->entity->getFileUri();
            $generic_thumbnail = \Drupal::service('file_url_generator')->generateAbsoluteString("public://media-icons/generic/image.png");;
          }
          else if ($iterator->hasField('field_media_audio_file')) {
            $file_uri = $iterator->field_media_audio_file->entity->getFileUri();
            $generic_thumbnail = \Drupal::service('file_url_generator')->generateAbsoluteString("public://media-icons/generic/audio.png");;
          }
          else if ($iterator->hasField('field_media_video_file')) {
            $file_uri = $iterator->field_media_video_file->entity->getFileUri();
            $generic_thumbnail = \Drupal::service('file_url_generator')->generateAbsoluteString("public://media-icons/generic/video.png");;
          }
          else if ($iterator->hasField('field_media_file')) {
            $file_uri = $iterator->field_media_file->entity->getFileUri();
            $generic_thumbnail = \Drupal::service('file_url_generator')->generateAbsoluteString("public://media-icons/generic/generic.png");;
          }
          else if ($iterator->hasField('field_media_document')) {
            $file_uri = $iterator->field_media_document->entity->getFileUri();
            $generic_thumbnail = \Drupal::service('file_url_generator')->generateAbsoluteString("public://media-icons/generic/audio.png");;
          }

          $fields = $this->getFieldsHelper()->filterForPropertyPath($item->getFields(), NULL,
            'search_api_islandora_object_thumbnail');
          if (isset($file_uri)) {
            $style = \Drupal::entityTypeManager()->getStorage('image_style')->load('large');
            $file_url = $style->buildUrl($file_uri.".jpg");
            foreach ($fields as $field) {
              if ($this->is_404($file_url)) {
                $field->addValue($generic_thumbnail);
              }
              else {
                $field->addValue($file_url);
              }
            }
          }
          // break since we assume that one media has ONLY one thumbnail
          break;
        }
      }
    }
  }

}
