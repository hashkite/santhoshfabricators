(function ($, Drupal) {
  Drupal.behaviors.projectSiteTabs = {
    attach: function (context, settings) {
      $(once('projectSiteTabs', '.tabs .tab', context)).on('click', function () {
        const $this = $(this);
        const tabId = $this.data('tab');

        // Toggle active class on buttons
        $this.addClass('active').siblings().removeClass('active');

        // Toggle active class on content
        $('#' + tabId).addClass('active').siblings().removeClass('active');
      });
    }
  };
})(jQuery, Drupal);
