(function ($) {
  function renumber($repeater, updateSort) {
    $repeater.find('.wclo-repeater-row').each(function (index) {
      $(this).find('[name]').each(function () {
        const name = $(this).attr('name');
        if (!name) return;
        $(this).attr('name', name.replace(/\[(?:__INDEX__|\d+)\]/, `[${index}]`));
      });
      const $sort = $(this).find('[name$="[sort_order]"]');
      if (updateSort && $sort.length) {
        $sort.val((index + 1) * 10);
      }
    });
  }

  function initWooSearch($scope) {
    if (!window.jQuery) return;
    $scope.find('.select2-hidden-accessible').each(function () {
      try {
        $(this).selectWoo('destroy');
      } catch (error) {
        try {
          $(this).select2('destroy');
        } catch (ignored) {}
      }
    });
    $scope.find('.select2-container').remove();
    $(document.body).trigger('wc-enhanced-select-init');
  }

  $(document).on('click', '[data-wclo-add-row]', function () {
    const $repeater = $(this).closest('[data-wclo-repeater]');
    const template = $repeater.find('template[data-wclo-template]').html();
    if (!template) return;
    const index = $repeater.find('.wclo-repeater-row').length;
    const $row = $(template.replace(/__INDEX__/g, String(index)));
    $repeater.find('[data-wclo-rows]').append($row);
    renumber($repeater, true);
    initWooSearch($row);
  });

  $(document).on('click', '[data-wclo-remove-row]', function () {
    const $repeater = $(this).closest('[data-wclo-repeater]');
    $(this).closest('.wclo-repeater-row').remove();
    renumber($repeater, true);
  });

  $(document).on('click', '[data-wclo-move]', function () {
    const $row = $(this).closest('.wclo-repeater-row');
    const $repeater = $(this).closest('[data-wclo-repeater]');
    if ($(this).data('wcloMove') === 'up') {
      $row.prev('.wclo-repeater-row').before($row);
    } else {
      $row.next('.wclo-repeater-row').after($row);
    }
    renumber($repeater, true);
    initWooSearch($repeater);
  });

  $(document).on('change', '.wc-product-search', function () {
    const $price = $(this).closest('.wclo-repeater-row').find('[data-wclo-product-price] strong');
    if ($price.length) {
      $price.text('Save changes to refresh price.');
    }
  });

  $(function () {
    $('[data-wclo-repeater]').each(function () {
      renumber($(this), false);
    });
    initWooSearch($(document));
  });
})(jQuery);
