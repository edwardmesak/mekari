<?php
/**
 * Admin UI helpers for table headers and tooltips.
 */

if (!defined('ABSPATH')) {
  exit;
}

trait LM_Admin_UI_Trait {
  private function table_header_with_tooltip($class, $label, $tooltip = '', $align = '') {
    $tooltipClass = 'lm-tooltip';
    if ($align === 'left') {
      $tooltipClass .= ' is-left';
    } elseif ($align === 'right') {
      $tooltipClass .= ' is-right';
    }

    $inner = esc_html((string)$label);
    if ((string)$tooltip !== '') {
      $inner .= ' <span class="' . esc_attr($tooltipClass) . '" data-tooltip="' . esc_attr((string)$tooltip) . '" tabindex="0" role="img" aria-label="' . esc_attr((string)$tooltip) . '">ⓘ</span>';
    }

    return '<th class="' . esc_attr((string)$class) . '">' . $inner . '</th>';
  }
}