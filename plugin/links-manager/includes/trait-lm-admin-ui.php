<?php
/**
 * Admin UI helpers for table headers and tooltips.
 */

if (!defined('ABSPATH')) {
  exit;
}

trait LM_Admin_UI_Trait {
  private function render_admin_page_header($title, $subtitle = '') {
    echo '<div class="lm-page-hero">';
    echo '<div class="lm-page-hero__content">';
    echo '<h1 class="lm-page-title">' . esc_html((string)$title) . '</h1>';
    if ((string)$subtitle !== '') {
      echo '<p class="lm-page-subtitle">' . esc_html((string)$subtitle) . '</p>';
    }
    echo '</div>';
    echo '</div>';
  }

  private function render_admin_section_intro($title, $description = '') {
    echo '<div class="lm-section-intro">';
    echo '<h2 class="lm-section-title">' . esc_html((string)$title) . '</h2>';
    if ((string)$description !== '') {
      echo '<p class="lm-section-description">' . esc_html((string)$description) . '</p>';
    }
    echo '</div>';
  }

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
