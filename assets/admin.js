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
      $clone.find('.tkcm-remove').on('click', function(e){ e.preventDefault(); $clone.remove(); reindex(); });
      $list.append($clone);
      reindex();
    });

    $list.on('click', '.tkcm-remove', function(e){
      e.preventDefault();
      $(this).closest('.tkcm-row').remove();
      reindex();
    });
  });
})(jQuery);

