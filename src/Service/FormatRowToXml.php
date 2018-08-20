<?php

namespace Drupal\views_oai_pmh\Service;

class FormatRowToXml {

  public function transform(array $row): array {
    $output = [];

    foreach ($row as $alias => $value) {

      if ($attribute = $this->hasAttribute($alias)){
        $tag_name = $attribute[0];
        $tag_attr_name = $attribute[1];

        if (is_array($output[$tag_name]) && !array_key_exists('#', $output[$tag_name])) {
          foreach ($output[$tag_name] as $id => $val) {
            if (is_array($value)) {
              $val = $value[$id];
            }
            else {
              $val = $value;
            }

            $output[$tag_name][$id]['@' . $tag_attr_name] = $val;
          }
        }
        else {
          $output[$tag_name]['@' . $tag_attr_name] = $value;
        }

        $b = 1;
      }
      else {
        $tag_name = $alias;
        $tag_attr_name = NULL;

        if (is_array($value)) {
          foreach ($value as $item) {
            $output[$tag_name][] = [
              '#' => $item,
            ];
          }
        }
        else {
          $output[$tag_name]['#'] = $value;
        }
      }

    }

    return $output;
  }

  protected function depth($alias) {
    $parts = explode('>', $alias);

    if (count($parts) === 1) {
      return $alias;
    }

    return $parts;
  }

  protected function hasAttribute($alias) {
    $att = explode('@', $alias);
    if (count($att) > 1) {
      return $att;
    }

    return FALSE;
  }

}