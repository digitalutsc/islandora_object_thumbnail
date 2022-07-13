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

  /**
   * {@inheritdoc}
   */
  public function addFieldValues(ItemInterface $item) {
    $datasourceId = $item->getDatasourceId();
    if ($datasourceId == 'entity:node') {


      // process to get the thumbnail
      $node = $item->getOriginalObject()->getValue();
      if ($node->hasField('field_islandora_object_media')) {
        $medias = $node->get('field_islandora_object_media');
        $thumbnail = null;
        if (count($medias)> 1) {
          foreach ($medias as $media) {
            if ($media->hasField("field_media_use") && $media->get("field_media_use")->getValue() == "Thumbnail Image") {
              $thumbnail = $media;
              break;
            }
          }
        }
        else if (count($medias) == 1) {
          $thumbnail = $medias[0];
        }

        if (isset($thumbnail)) {
          $found = Media::load($thumbnail->getValue()['target_id']);
          $file_uri = null;
          if ($found->hasField('field_media_image')) {
            $file_uri = $found->field_media_image->entity->getFileUri();
          }
          else if ($found->hasField('field_media_audio_file')) {
            $file_uri = $found->field_media_audio_file->entity->getFileUri();
          }
          else if ($found->hasField('field_media_video_file')) {
            $file_uri = $found->field_media_video_file->entity->getFileUri();
          }
          else if ($found->hasField('field_media_file')) {
            $file_uri = $found->field_media_file->entity->getFileUri();
          }
          else if ($found->hasField('field_media_document')) {
            $file_uri = $found->field_media_document->entity->getFileUri();
          }
          if (isset($file_uri)) {
            $style = ImageStyle::load('thumbnail');
            $file_url = $style->buildUrl($file_uri);
            $fields = $this->getFieldsHelper()->filterForPropertyPath($item->getFields(), NULL, 'search_api_islandora_object_thumbnail');
            foreach ($fields as $field) {
              $field->addValue($file_url);
            }
          }
        }
      }
    }
  }

}
