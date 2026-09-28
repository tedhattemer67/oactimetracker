
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

  function recalc(){
    var reg=0,vac=0,sick=0,pers=0,hol=0,unp=0,ot=0,grand=0;
    $('#tt-admin-grid tbody tr.tt-row').each(function(){
      var $tr = $(this);
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
