<?php
/**
 * Admin message feedback helpers.
 */

if (!defined('ABSPATH')) {
  exit;
}

trait LM_Admin_Feedback_Trait {
  private function notice_class_for_message($msg, $default = 'info') {
    $text = strtolower(trim((string)$msg));
    if ($text === '') return (string)$default;

    if (
      strpos($text, 'failed') === 0 ||
      strpos($text, 'error') === 0 ||
      strpos($text, 'invalid') === 0 ||
      strpos($text, 'unauthorized') === 0 ||
      strpos($text, 'no ') === 0 ||
      strpos($text, 'cannot') !== false ||
      strpos($text, 'not found') !== false
    ) {
      return 'error';
    }

    if (
      strpos($text, 'success') === 0 ||
      strpos($text, 'settings saved') === 0 ||
      strpos($text, 'group saved') === 0 ||
      strpos($text, 'group updated') === 0 ||
      strpos($text, 'group deleted') === 0 ||
      strpos($text, 'targets saved') === 0 ||
      strpos($text, 'target updated') === 0 ||
      strpos($text, 'target deleted') === 0 ||
      strpos($text, 'deleted ') === 0
    ) {
      return 'success';
    }

    return (string)$default;
  }
}