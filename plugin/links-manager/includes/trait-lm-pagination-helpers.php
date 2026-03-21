<?php
/**
 * Pagination rendering helpers for Links Manager admin screens.
 */

if (!defined('ABSPATH')) {
  exit;
}

trait LM_Pagination_Helpers_Trait {
  private function render_pages_link_pagination($filters, $paged, $totalPages) {
    if ($totalPages <= 1) return;

    echo '<div class="tablenav" style="margin:10px 0;">';
    echo '<div class="tablenav-pages">';
    echo '<span class="displaying-num">Page ' . esc_html((string)$paged) . ' of ' . esc_html((string)$totalPages) . '</span> ';

    $prev = max(1, $paged - 1);
    $next = min($totalPages, $paged + 1);

    echo '<a class="button" href="' . esc_url($this->pages_link_admin_url($filters, ['lm_pages_link_paged' => 1])) . '">&laquo; First</a> ';
    echo '<a class="button" href="' . esc_url($this->pages_link_admin_url($filters, ['lm_pages_link_paged' => $prev])) . '">&lsaquo; Previous</a> ';
    echo '<a class="button" href="' . esc_url($this->pages_link_admin_url($filters, ['lm_pages_link_paged' => $next])) . '">Next &rsaquo;</a> ';
    echo '<a class="button" href="' . esc_url($this->pages_link_admin_url($filters, ['lm_pages_link_paged' => $totalPages])) . '">Last &raquo;</a>';

    echo '</div></div>';
  }

  private function render_pagination($filters, $paged, $totalPages) {
    if ($totalPages <= 1) return;

    echo '<div class="tablenav" style="margin:10px 0;">';
    echo '<div class="tablenav-pages">';
    echo '<span class="displaying-num">Page ' . esc_html((string)$paged) . ' of ' . esc_html((string)$totalPages) . '</span> ';

    $prev = max(1, $paged - 1);
    $next = min($totalPages, $paged + 1);

    echo '<a class="button" href="' . esc_url($this->base_admin_url($filters, ['lm_paged' => 1])) . '">&laquo; First</a> ';
    echo '<a class="button" href="' . esc_url($this->base_admin_url($filters, ['lm_paged' => $prev])) . '">&lsaquo; Previous</a> ';
    echo '<a class="button" href="' . esc_url($this->base_admin_url($filters, ['lm_paged' => $next])) . '">Next &rsaquo;</a> ';
    echo '<a class="button" href="' . esc_url($this->base_admin_url($filters, ['lm_paged' => $totalPages])) . '">Last &raquo;</a>';

    echo '</div></div>';
  }

  private function render_target_pagination($paged, $totalPages, $queryParams = []) {
    if ($totalPages <= 1) return;

    echo '<div class="tablenav" style="margin:10px 0;">';
    echo '<div class="tablenav-pages">';
    echo '<span class="displaying-num">Page ' . esc_html((string)$paged) . ' of ' . esc_html((string)$totalPages) . '</span> ';

    $prev = max(1, $paged - 1);
    $next = min($totalPages, $paged + 1);

    $baseParams = array_merge(['page' => 'links-manager-target'], $queryParams);

    $firstUrl = add_query_arg(array_merge($baseParams, ['lm_summary_paged' => 1]), admin_url('admin.php'));
    $prevUrl = add_query_arg(array_merge($baseParams, ['lm_summary_paged' => $prev]), admin_url('admin.php'));
    $nextUrl = add_query_arg(array_merge($baseParams, ['lm_summary_paged' => $next]), admin_url('admin.php'));
    $lastUrl = add_query_arg(array_merge($baseParams, ['lm_summary_paged' => $totalPages]), admin_url('admin.php'));

    echo '<a class="button" href="' . esc_url($firstUrl) . '">&laquo; First</a> ';
    echo '<a class="button" href="' . esc_url($prevUrl) . '">&lsaquo; Previous</a> ';
    echo '<a class="button" href="' . esc_url($nextUrl) . '">Next &rsaquo;</a> ';
    echo '<a class="button" href="' . esc_url($lastUrl) . '">Last &raquo;</a>';

    echo '</div></div>';
  }

  private function render_cited_domains_pagination($filters, $paged, $totalPages) {
    if ($totalPages <= 1) return;

    echo '<div class="tablenav" style="margin:10px 0;">';
    echo '<div class="tablenav-pages">';
    echo '<span class="displaying-num">Page ' . esc_html((string)$paged) . ' of ' . esc_html((string)$totalPages) . '</span> ';

    $prev = max(1, $paged - 1);
    $next = min($totalPages, $paged + 1);

    echo '<a class="button" href="' . esc_url($this->cited_domains_admin_url($filters, ['lm_cd_paged' => 1])) . '">&laquo; First</a> ';
    echo '<a class="button" href="' . esc_url($this->cited_domains_admin_url($filters, ['lm_cd_paged' => $prev])) . '">&lsaquo; Previous</a> ';
    echo '<a class="button" href="' . esc_url($this->cited_domains_admin_url($filters, ['lm_cd_paged' => $next])) . '">Next &rsaquo;</a> ';
    echo '<a class="button" href="' . esc_url($this->cited_domains_admin_url($filters, ['lm_cd_paged' => $totalPages])) . '">Last &raquo;</a>';

    echo '</div></div>';
  }

  private function render_all_anchor_text_pagination($filters, $paged, $totalPages) {
    if ($totalPages <= 1) return;

    echo '<div class="tablenav" style="margin:10px 0;">';
    echo '<div class="tablenav-pages">';
    echo '<span class="displaying-num">Page ' . esc_html((string)$paged) . ' of ' . esc_html((string)$totalPages) . '</span> ';

    $prev = max(1, $paged - 1);
    $next = min($totalPages, $paged + 1);

    echo '<a class="button" href="' . esc_url($this->all_anchor_text_admin_url($filters, ['lm_at_paged' => 1])) . '">&laquo; First</a> ';
    echo '<a class="button" href="' . esc_url($this->all_anchor_text_admin_url($filters, ['lm_at_paged' => $prev])) . '">&lsaquo; Previous</a> ';
    echo '<a class="button" href="' . esc_url($this->all_anchor_text_admin_url($filters, ['lm_at_paged' => $next])) . '">Next &rsaquo;</a> ';
    echo '<a class="button" href="' . esc_url($this->all_anchor_text_admin_url($filters, ['lm_at_paged' => $totalPages])) . '">Last &raquo;</a>';

    echo '</div></div>';
  }
}
