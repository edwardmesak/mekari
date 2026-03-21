<?php
/**
 * Pagination rendering helpers for Links Manager admin screens.
 */

if (!defined('ABSPATH')) {
  exit;
}

trait LM_Pagination_Helpers_Trait {
  private function get_pagination_range_text($paged, $perPage, $total) {
    $paged = max(1, (int)$paged);
    $perPage = max(1, (int)$perPage);
    $total = max(0, (int)$total);

    if ($total <= 0) {
      return 'Showing 0-0 of 0';
    }

    $start = (($paged - 1) * $perPage) + 1;
    $end = min($total, $paged * $perPage);
    return sprintf('Showing %d-%d of %d', $start, $end, $total);
  }

  private function get_rest_pagination_html($paged, $totalPages, $total = null, $perPage = null) {
    $paged = max(1, (int)$paged);
    $totalPages = max(1, (int)$totalPages);

    if ($totalPages <= 1) {
      return '';
    }

    $prev = max(1, $paged - 1);
    $next = min($totalPages, $paged + 1);

    ob_start();
    echo '<div class="tablenav lm-pagination">';
    echo '<div class="tablenav-pages">';
    if ($total !== null && $perPage !== null) {
      echo '<span class="lm-pagination-summary">' . esc_html($this->get_pagination_range_text($paged, (int)$perPage, (int)$total)) . '</span> ';
    }
    echo '<span class="displaying-num">Page ' . esc_html((string)$paged) . ' of ' . esc_html((string)$totalPages) . '</span> ';
    echo '<button type="button" class="button" data-lm-rest-page="1">&laquo; First</button> ';
    echo '<button type="button" class="button" data-lm-rest-page="' . esc_attr((string)$prev) . '"' . disabled($paged <= 1, true, false) . '>&lsaquo; Previous</button> ';
    echo '<button type="button" class="button" data-lm-rest-page="' . esc_attr((string)$next) . '"' . disabled($paged >= $totalPages, true, false) . '>Next &rsaquo;</button> ';
    echo '<button type="button" class="button" data-lm-rest-page="' . esc_attr((string)$totalPages) . '"' . disabled($paged >= $totalPages, true, false) . '>Last &raquo;</button>';
    echo '</div></div>';
    return (string)ob_get_clean();
  }

  private function render_pages_link_pagination($filters, $paged, $totalPages, $total = null, $perPage = null) {
    if ($totalPages <= 1) return;

    echo '<div class="tablenav lm-pagination">';
    echo '<div class="tablenav-pages">';
    if ($total !== null && $perPage !== null) {
      echo '<span class="lm-pagination-summary">' . esc_html($this->get_pagination_range_text($paged, (int)$perPage, (int)$total)) . '</span> ';
    }
    echo '<span class="displaying-num">Page ' . esc_html((string)$paged) . ' of ' . esc_html((string)$totalPages) . '</span> ';

    $prev = max(1, $paged - 1);
    $next = min($totalPages, $paged + 1);

    echo '<a class="button" href="' . esc_url($this->pages_link_admin_url($filters, ['lm_pages_link_paged' => 1])) . '">&laquo; First</a> ';
    echo '<a class="button" href="' . esc_url($this->pages_link_admin_url($filters, ['lm_pages_link_paged' => $prev])) . '">&lsaquo; Previous</a> ';
    echo '<a class="button" href="' . esc_url($this->pages_link_admin_url($filters, ['lm_pages_link_paged' => $next])) . '">Next &rsaquo;</a> ';
    echo '<a class="button" href="' . esc_url($this->pages_link_admin_url($filters, ['lm_pages_link_paged' => $totalPages])) . '">Last &raquo;</a>';

    echo '</div></div>';
  }

  private function render_pagination($filters, $paged, $totalPages, $total = null, $perPage = null) {
    if ($totalPages <= 1) return;

    echo '<div class="tablenav lm-pagination">';
    echo '<div class="tablenav-pages">';
    if ($total !== null && $perPage !== null) {
      echo '<span class="lm-pagination-summary">' . esc_html($this->get_pagination_range_text($paged, (int)$perPage, (int)$total)) . '</span> ';
    }
    echo '<span class="displaying-num">Page ' . esc_html((string)$paged) . ' of ' . esc_html((string)$totalPages) . '</span> ';

    $prev = max(1, $paged - 1);
    $next = min($totalPages, $paged + 1);

    echo '<a class="button" href="' . esc_url($this->base_admin_url($filters, ['lm_paged' => 1])) . '">&laquo; First</a> ';
    echo '<a class="button" href="' . esc_url($this->base_admin_url($filters, ['lm_paged' => $prev])) . '">&lsaquo; Previous</a> ';
    echo '<a class="button" href="' . esc_url($this->base_admin_url($filters, ['lm_paged' => $next])) . '">Next &rsaquo;</a> ';
    echo '<a class="button" href="' . esc_url($this->base_admin_url($filters, ['lm_paged' => $totalPages])) . '">Last &raquo;</a>';

    echo '</div></div>';
  }

  private function render_target_pagination($paged, $totalPages, $queryParams = [], $total = null, $perPage = null) {
    if ($totalPages <= 1) return;

    echo '<div class="tablenav lm-pagination">';
    echo '<div class="tablenav-pages">';
    if ($total !== null && $perPage !== null) {
      echo '<span class="lm-pagination-summary">' . esc_html($this->get_pagination_range_text($paged, (int)$perPage, (int)$total)) . '</span> ';
    }
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

  private function render_query_pagination($pageSlug, $pageParam, $paged, $totalPages, $queryParams = [], $total = null, $perPage = null) {
    if ($totalPages <= 1) return;

    $paged = max(1, (int)$paged);
    $totalPages = max(1, (int)$totalPages);
    $prev = max(1, $paged - 1);
    $next = min($totalPages, $paged + 1);
    $baseParams = array_merge(['page' => $pageSlug], $queryParams);

    $firstUrl = add_query_arg(array_merge($baseParams, [$pageParam => 1]), admin_url('admin.php'));
    $prevUrl = add_query_arg(array_merge($baseParams, [$pageParam => $prev]), admin_url('admin.php'));
    $nextUrl = add_query_arg(array_merge($baseParams, [$pageParam => $next]), admin_url('admin.php'));
    $lastUrl = add_query_arg(array_merge($baseParams, [$pageParam => $totalPages]), admin_url('admin.php'));

    echo '<div class="tablenav lm-pagination">';
    echo '<div class="tablenav-pages">';
    if ($total !== null && $perPage !== null) {
      echo '<span class="lm-pagination-summary">' . esc_html($this->get_pagination_range_text($paged, (int)$perPage, (int)$total)) . '</span> ';
    }
    echo '<span class="displaying-num">Page ' . esc_html((string)$paged) . ' of ' . esc_html((string)$totalPages) . '</span> ';
    echo '<a class="button" href="' . esc_url($firstUrl) . '">&laquo; First</a> ';
    echo '<a class="button" href="' . esc_url($prevUrl) . '">&lsaquo; Previous</a> ';
    echo '<a class="button" href="' . esc_url($nextUrl) . '">Next &rsaquo;</a> ';
    echo '<a class="button" href="' . esc_url($lastUrl) . '">Last &raquo;</a>';
    echo '</div></div>';
  }

  private function render_cited_domains_pagination($filters, $paged, $totalPages, $total = null, $perPage = null) {
    if ($totalPages <= 1) return;

    echo '<div class="tablenav lm-pagination">';
    echo '<div class="tablenav-pages">';
    if ($total !== null && $perPage !== null) {
      echo '<span class="lm-pagination-summary">' . esc_html($this->get_pagination_range_text($paged, (int)$perPage, (int)$total)) . '</span> ';
    }
    echo '<span class="displaying-num">Page ' . esc_html((string)$paged) . ' of ' . esc_html((string)$totalPages) . '</span> ';

    $prev = max(1, $paged - 1);
    $next = min($totalPages, $paged + 1);

    echo '<a class="button" href="' . esc_url($this->cited_domains_admin_url($filters, ['lm_cd_paged' => 1])) . '">&laquo; First</a> ';
    echo '<a class="button" href="' . esc_url($this->cited_domains_admin_url($filters, ['lm_cd_paged' => $prev])) . '">&lsaquo; Previous</a> ';
    echo '<a class="button" href="' . esc_url($this->cited_domains_admin_url($filters, ['lm_cd_paged' => $next])) . '">Next &rsaquo;</a> ';
    echo '<a class="button" href="' . esc_url($this->cited_domains_admin_url($filters, ['lm_cd_paged' => $totalPages])) . '">Last &raquo;</a>';

    echo '</div></div>';
  }

  private function render_all_anchor_text_pagination($filters, $paged, $totalPages, $total = null, $perPage = null) {
    if ($totalPages <= 1) return;

    echo '<div class="tablenav lm-pagination">';
    echo '<div class="tablenav-pages">';
    if ($total !== null && $perPage !== null) {
      echo '<span class="lm-pagination-summary">' . esc_html($this->get_pagination_range_text($paged, (int)$perPage, (int)$total)) . '</span> ';
    }
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
