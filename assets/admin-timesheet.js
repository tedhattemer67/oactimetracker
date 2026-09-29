
(function($){
  function parseTime(t){
    if(!t) return null;
    var m = /^(\d{1,2}):(\d{2})$/.exec(t);
    if(!m) return null;
    var h = parseInt(m[1],10), mm = parseInt(m[2],10);
    if(isNaN(h)||isNaN(mm)) return null;
    return h + (mm/60);
  }
  function spanHours(i,o){
    var a = parseTime(i), b = parseTime(o);
    if(a==null || b==null) return 0;
    var d = b - a;
    return d > 0 ? Math.round(d*100)/100 : 0;
  }
  function num(v){ v=parseFloat(v); return isNaN(v)?0: v; }

  // No overnight shifts: flag an Out that isn't after its In. setCustomValidity also
  // stops the Update button from submitting until it's fixed.
  function markPair($in, $out){
    var a = parseTime($in.val()), b = parseTime($out.val());
    var bad = (a != null && b != null && b <= a);
    var el = $out.get(0);
    if(el && el.setCustomValidity) el.setCustomValidity(bad ? 'Time Out must be later than Time In (no overnight shifts).' : '');
    $out.css('outline', bad ? '2px solid #d63638' : '');
  }

  function recalc(){
    var reg=0,vac=0,sick=0,pers=0,hol=0,unp=0,ot=0,grand=0;
    $('#tt-admin-grid tbody tr.tt-row').each(function(){
      var $tr = $(this);
      markPair($tr.find('.tt-in1'), $tr.find('.tt-out1'));
      markPair($tr.find('.tt-in2'), $tr.find('.tt-out2'));
      var h = spanHours($tr.find('.tt-in1').val(), $tr.find('.tt-out1').val()) +
              spanHours($tr.find('.tt-in2').val(), $tr.find('.tt-out2').val());
      var v = num($tr.find('.tt-vac').val());
      var s = num($tr.find('.tt-sick').val());
      var p = num($tr.find('.tt-pers').val());
      var hh= num($tr.find('.tt-hol').val());
      var u = num($tr.find('.tt-unp').val());
      var o = num($tr.find('.tt-ot').val());
      var dayTotal = Math.round((h+v+s+p+hh+u+o)*100)/100;
      $tr.find('.tt-daily-total').text(dayTotal.toFixed(2));

      reg += h; vac += v; sick += s; pers += p; hol += hh; unp += u; ot += o; grand += dayTotal;
    });
    $('.tt-total-reg').text(reg.toFixed(2));
    $('.tt-total-vac').text(vac.toFixed(2));
    $('.tt-total-sick').text(sick.toFixed(2));
    $('.tt-total-pers').text(pers.toFixed(2));
    $('.tt-total-hol').text(hol.toFixed(2));
    $('.tt-total-unp').text(unp.toFixed(2));
    $('.tt-total-ot').text(ot.toFixed(2));
    $('.tt-total-grand').text(grand.toFixed(2));
  }

  $(document).on('input change', '#tt-admin-metabox input', recalc);
  $(document).ready(recalc);
})(jQuery);
