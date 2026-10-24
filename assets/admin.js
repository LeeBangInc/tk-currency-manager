(function($){
  $(function(){
    var $list = $('#tkcm-currency-list');
    var $template = $('#tkcm-row-template .tkcm-row');

    function reindex(){
      var i = 0;
      $list.find('.tkcm-row').not('.tkcm-head').each(function(){
        var $row = $(this);
        $row.attr('id', 'tkcm-row-' + i);
        $row.find('input[name^="cur-name-id"]').attr('name', 'cur-name-id-' + i);
        $row.find('input[name^="cur-val-id"]').attr('name', 'cur-val-id-' + i);
        $row.find('input[name^="cur-desc-id"]').attr('name', 'cur-desc-id-' + i);
        i++;
      });
      $('input[name="element-max-id"]').val(i);
    }

    $list.sortable({
      items: '> .tkcm-row:not(.tkcm-head)',
      handle: '.tkcm-drag',
      stop: reindex
    });

    $('#tkcm-add-currency').on('click', function(e){
      e.preventDefault();
      var $clone = $template.clone();
      $list.append($clone);
      reindex();
    });

    $('#tkcm-recalculate').on('click', function(){
      var $button = $(this).prop('disabled', true);
      var $status = $('#tkcm-recalculate-status').empty();
      var totals = {updated: 0, skipped: 0, errors: 0};
      var messages = [];
      var page = 1;

      function render(suffix) {
        var summary = tkcmAdmin.progress
          .replace('%1$d', totals.updated)
          .replace('%2$d', totals.skipped)
          .replace('%3$d', totals.errors);
        $status.empty().append($('<p>').text(summary + ' ' + suffix));
        messages.forEach(function(message){ $status.append($('<p>').text(message)); });
      }

      function next() {
        $.post(ajaxurl, {action: 'tkcm_recalculate', nonce: tkcmAdmin.nonce, page: page})
          .done(function(response){
            if (!response.success) {
              render(tkcmAdmin.failed);
              $button.prop('disabled', false);
              return;
            }
            ['updated', 'skipped', 'errors'].forEach(function(key){ totals[key] += response.data[key]; });
            messages = messages.concat(response.data.messages).slice(0, 10);
            render(response.data.done ? tkcmAdmin.complete : '');
            if (response.data.done) {
              $button.prop('disabled', false);
            } else {
              page++;
              next();
            }
          })
          .fail(function(){ render(tkcmAdmin.failed); $button.prop('disabled', false); });
      }
      next();
    });

    $list.on('click', '.tkcm-remove', function(e){
      e.preventDefault();
      $(this).closest('.tkcm-row').remove();
      reindex();
    });
  });
})(jQuery);

