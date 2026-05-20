(function ($, Drupal) {
  /**
   * Project Detail Page — Tab switching behavior.
   * Handles both the original .tabs .tab (legacy) and new .project-tabs-nav .project-tab.
   */
  Drupal.behaviors.projectSiteTabs = {
    attach: function (context, settings) {

      // ── Legacy Tab Handler ──
      $(once('projectSiteTabs', '.tabs .tab', context)).on('click', function () {
        const $this = $(this);
        const tabId = $this.data('tab');

        $this.addClass('active').siblings().removeClass('active');
        const $tabContents = $('.tab-content');
        $tabContents.removeClass('active');

        setTimeout(function () {
          $('#' + tabId).addClass('active');
        }, 50);
      });

      // ── New Project Detail Tab Handler ──
      $(once('projectDetailTabs', '.project-tabs-nav .project-tab', context)).on('click', function () {
        const $this = $(this);
        const tabId = $this.data('tab');

        // Toggle active on buttons
        $this.addClass('active').siblings().removeClass('active');

        // Toggle active on panels with animation
        const $panels = $('.project-tab-panel');
        $panels.removeClass('active');

        setTimeout(function () {
          $('#tab-' + tabId).addClass('active');
        }, 50);
      });

      // ── Dashboard "Create New Site" Card Injection (logged-in only) ──
      var $ul = $('.view-project-dashboard .view-content .item-list ul', context);
      if ($ul.length && !$ul.find('.create-new-site-card-li').length && drupalSettings.user && drupalSettings.user.uid > 0) {
        var createNewSiteHtml = `
          <li class="create-new-site-card-li">
            <a href="/node/add/project_sites" class="create-new-site-card">
              <div class="create-new-site-inner">
                <div class="plus-icon-circle">
                  <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="12" y1="5" x2="12" y2="19"></line>
                    <line x1="5" y1="12" x2="19" y2="12"></line>
                  </svg>
                </div>
                <span class="create-title">Create New Site</span>
                <span class="create-subtitle">Initialize new project PID</span>
              </div>
            </a>
          </li>
        `;
        $ul.append(createNewSiteHtml);
      }

      // ── Dashboard Card Stagger Animation ──
      $(once('cardStagger', '.view-project-dashboard .view-content .item-list ul li', context)).each(function (index) {
        var $card = $(this);
        $card.css({
          'opacity': '0',
          'transform': 'translateY(20px)'
        });
        setTimeout(function () {
          $card.css({
            'transition': 'opacity 0.4s ease-out, transform 0.4s ease-out',
            'opacity': '1',
            'transform': 'translateY(0)'
          });
        }, 80 * index);
      });

      // ── Budget Progress Bar Animation ──
      $(once('budgetProgressAnim', '.budget-progress__fill', context)).each(function () {
        var $bar = $(this);
        var targetWidth = $bar.css('width');
        $bar.css('width', '0');
        setTimeout(function () {
          $bar.css('width', targetWidth);
        }, 300);
      });
    }
  };

  /**
   * Mobile sidebar toggle behavior.
   */
  Drupal.behaviors.sidebarToggle = {
    attach: function (context, settings) {
      $(once('sidebarToggle', '.fabritrack-layout', context)).each(function () {
        var $layout = $(this);
        var $sidebar = $layout.find('.fabritrack-sidebar');
        var $header = $layout.find('.fabritrack-header');

        // Create hamburger button (only visible on ≤1024px via CSS)
        if (!$layout.find('.sidebar-toggle-btn').length) {
          var $toggleBtn = $('<button class="sidebar-toggle-btn" aria-label="Toggle sidebar menu" type="button">' +
            '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
            '<line x1="3" y1="6" x2="21" y2="6"></line>' +
            '<line x1="3" y1="12" x2="21" y2="12"></line>' +
            '<line x1="3" y1="18" x2="21" y2="18"></line>' +
            '</svg></button>');

          $header.prepend($toggleBtn);

          var $overlay = $('<div class="sidebar-overlay"></div>');
          $layout.append($overlay);

          $toggleBtn.on('click', function (e) {
            e.preventDefault();
            $sidebar.toggleClass('sidebar-open');
            $overlay.toggleClass('active');
            $('body').toggleClass('sidebar-is-open');
          });

          $overlay.on('click', function () {
            $sidebar.removeClass('sidebar-open');
            $overlay.removeClass('active');
            $('body').removeClass('sidebar-is-open');
          });

          $(document).on('keydown.sidebarToggle', function (e) {
            if (e.key === 'Escape' && $sidebar.hasClass('sidebar-open')) {
              $sidebar.removeClass('sidebar-open');
              $overlay.removeClass('active');
              $('body').removeClass('sidebar-is-open');
            }
          });
        }
      });
    }
  };
})(jQuery, Drupal);
