(function($){
  $(function(){
    // Ensure html2pdf is actually there
    if (typeof html2pdf === 'undefined') {
      console.error('html2pdf.js not found! Check your enqueue.');
      return;
    }

    $('#pdf-button').on('click', function(e){
      e.preventDefault();
      console.log('📥 PDF button clicked');

      // 1) grab the element
      const element = document.getElementById('printable-area');
      if (!element) {
        console.error('❌ #printable-area not found in DOM');
        return;
      }

      // 2) pull filename from localized var, or fallback
      const filename = (window.MyPluginPDF && MyPluginPDF.filename) 
                         ? MyPluginPDF.filename 
                         : 'document.pdf';
      console.log('Using filename:', filename);

      // 3) options object
      const opt = {
        margin:       10,
        filename:     filename,
        image:        { type: 'jpeg', quality: 0.98 },
        html2canvas:  { scale: 2 },
        jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' }
      };

      // 4) generate & save
      html2pdf()
        .set(opt)
        .from(element)
        .save()
        .then(() => console.log('✅ PDF generated'))
        .catch(err => console.error('⚠️ html2pdf error:', err));
    });
  });
})(jQuery);
