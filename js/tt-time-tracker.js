(function($){
  $(function(){
    console.log('TT script loaded');

    const MS_DAY = 24*60*60*1000;
    const anchor = new Date(2026, 0, 11); // Sun, 01/11/2026 (month is 0-based)
    anchor.setHours(0,0,0,0);

    function addDays(dateObj, days){
      const d = new Date(dateObj.getTime());
      d.setDate(d.getDate() + days);
      d.setHours(0,0,0,0);
      return d;
    }
    function daysBetween(a,b){
      const A = Date.UTC(a.getFullYear(), a.getMonth(), a.getDate());
      const B = Date.UTC(b.getFullYear(), b.getMonth(), b.getDate());
      return Math.floor((A - B) / 86400000);
    }
    const today = new Date(); today.setHours(0,0,0,0);

    const sel = $('#pay-period');
    const timeBody = $('#time-grid .time-body');
    const ptoBody = $('#pto-grid .pto-body');

    function buildDropdown(){
      sel.empty();
      let diff = daysBetween(today, anchor);
      let cur = Math.floor(diff/14);
      if(cur<0)cur=0;
      for(let i=cur;i<cur+8;i++){
        let st = addDays(anchor, i*14);
      if(st.getDay() !== 0){
        const delta = (7 - st.getDay()) % 7; // Saturday->+1
        console.warn('[OAC Time Tracker] Pay period start not Sunday; auto-normalizing.', st);
        st = addDays(st, delta);
      }
        let en = addDays(st, 13);
        let val = `${st.getFullYear()}-${String(st.getMonth()+1).padStart(2,'0')}-${String(st.getDate()).padStart(2,'0')}`;
        let txt = `${st.getMonth()+1}/${st.getDate()}/${st.getFullYear()} – ${en.getMonth()+1}/${en.getDate()}/${en.getFullYear()}`;
        sel.append($('<option>').val(val).text(txt));
      }
    }

    function parseYMD(s){
      let [y,m,d]=s.split('-').map(Number);
      return new Date(y,m-1,d);
    }

    function recalcAll(){
      let sums={hrs:0,vac:0,sick:0,pers:0,hol:0,unp:0,ot:0,all:0};
      $('#time-grid .time-body tr').each(function(i){
        let in1=$(this).find('.in1').val(), out1=$(this).find('.out1').val();
        let in2=$(this).find('.in2').val(), out2=$(this).find('.out2').val();
        let total=0;
        if(in1&&out1) total+= (new Date(`1970-01-01T${out1}`)-new Date(`1970-01-01T${in1}`))/3600000;
        if(in2&&out2) total+= (new Date(`1970-01-01T${out2}`)-new Date(`1970-01-01T${in2}`))/3600000;
        $(this).find('.hours-total').text(total.toFixed(2));
        let ptoRow = $('#pto-grid .pto-body tr').eq(i);
        ptoRow.find('.pto-reg').text(total.toFixed(2));
        let vac=+ptoRow.find('.vac').val(), sick=+ptoRow.find('.sick').val();
        let pers=+ptoRow.find('.pers').val(), hol=+ptoRow.find('.hol').val();
        let unp=+ptoRow.find('.unp').val(), ot=+ptoRow.find('.ot').val();
        let rowTotal=total+vac+sick+pers+hol+unp+ot;
        ptoRow.find('.pto-total').text(rowTotal.toFixed(2));
        sums.hrs+=total; sums.vac+=vac; sums.sick+=sick; sums.pers+=pers;
        sums.hol+=hol; sums.unp+=unp; sums.ot+=ot; sums.all+=rowTotal;
      });
      $('#total-hours').text(sums.hrs.toFixed(2));
      $('#tot-reg').text(sums.hrs.toFixed(2)); $('#tot-vac').text(sums.vac.toFixed(2));
      $('#tot-sick').text(sums.sick.toFixed(2)); $('#tot-pers').text(sums.pers.toFixed(2));
      $('#tot-hol').text(sums.hol.toFixed(2)); $('#tot-unp').text(sums.unp.toFixed(2));
      $('#tot-ot').text(sums.ot.toFixed(2)); $('#tot-all').text(sums.all.toFixed(2));
    }

    function buildGrids(startIso){
      let start=parseYMD(startIso);
      $('#print-period').text(sel.find('option:selected').text());
      timeBody.empty(); ptoBody.empty();
      for(let i=0;i<14;i++){
        let d = addDays(start, i);
        let lbl=d.toLocaleDateString('en-US',{weekday:'short',month:'numeric',day:'numeric',year:'numeric'});
        let timeRow=$(`
          <tr>
            <td>${lbl}</td>
            <td><input type="time" name="time_in_1_${i}" class="in1"></td>
            <td><input type="time" name="time_out_1_${i}" class="out1"></td>
            <td><input type="time" name="time_in_2_${i}" class="in2"></td>
            <td><input type="time" name="time_out_2_${i}" class="out2"></td>
            <td class="hours-total">0.00</td>
          </tr>`);
        timeBody.append(timeRow);
        let ptoRow=$(`
          <tr>
            <td>${lbl}</td>
            <td class="pto-reg">0.00</td>
            <td><select name="vacation_${i}" class="vac">` +
              Array.from({length:17},(_,n)=>`<option value="${(n*0.5).toFixed(1)}">${(n*0.5).toFixed(1)}</option>`).join('') +
            `</select></td>
            <td><select name="sick_${i}" class="sick">` +
              Array.from({length:17},(_,n)=>`<option value="${(n*0.5).toFixed(1)}">${(n*0.5).toFixed(1)}</option>`).join('') +
            `</select></td>
            <td><select name="personal_${i}" class="pers">` +
              Array.from({length:17},(_,n)=>`<option value="${(n*0.5).toFixed(1)}">${(n*0.5).toFixed(1)}</option>`).join('') +
            `</select></td>
            <td><select name="holiday_${i}" class="hol"><option value="0.0">0</option><option value="8.0">8</option></select></td>
            <td><select name="unpaid_${i}" class="unp">` +
              Array.from({length:17},(_,n)=>`<option value="${(n*0.5).toFixed(1)}">${(n*0.5).toFixed(1)}</option>`).join('') +
            `</select></td>
            <td><select name="overtime_${i}" class="ot">` +
              Array.from({length:17},(_,n)=>`<option value="${(n*0.5).toFixed(1)}">${(n*0.5).toFixed(1)}</option>`).join('') +
            `</select></td>
            <td class="pto-total">0.00</td>
          </tr>`);
        ptoBody.append(ptoRow);
      }
      $('.in1, .out1, .in2, .out2, .vac, .sick, .pers, .hol, .unp, .ot').on('change', recalcAll);
      recalcAll();
    }

    // Admin/manager employee dropdown: mirror the selected option's user ID into the
    // hidden override field. Done before the first load so it asks for the right employee.
    const empSelect = document.getElementById('employee-select');
    const empUidField = document.getElementById('tt-employee-uid-override');
    function ttSyncEmployeeUid(){
      if(!empSelect || !empUidField || empSelect.tagName !== 'SELECT') return;
      const opt = empSelect.options[empSelect.selectedIndex];
      empUidField.value = (opt && opt.dataset.uid) || '';
    }
    ttSyncEmployeeUid();

    buildDropdown();
    sel.on('change', () => buildGrids(sel.val()));
    buildGrids(sel.val());
    // Load existing draft/submission for the initially-selected period
    setTimeout(function(){
      if(typeof ttLoadMyTimesheet === 'function') ttLoadMyTimesheet(sel.val());
    }, 50);

  


    function ttGetParam(name){
      const params = new URLSearchParams(window.location.search);
      return params.get(name) || '';
    }

    function ttSetStatus(msg){
      const el = document.getElementById('tt-draft-status');
      if(el) el.textContent = msg || '';
      ttSetChangesNote('');
    }

    // Manager's "Request Changes" note, shown in its own box. Kept out of #tt-draft-status
    // because the certify block keys off that element's text.
    function ttSetChangesNote(note){
      let box = document.getElementById('tt-changes-note');
      if(!note){ if(box) box.remove(); return; }
      if(!box){
        box = document.createElement('div');
        box.id = 'tt-changes-note';
        box.style.cssText = 'margin:10px 0;padding:10px;border-left:4px solid #d63638;background:#fff;white-space:pre-wrap;';
        const table = document.querySelector('#tt-container .tt-table-wrapper');
        if(table) table.parentNode.insertBefore(box, table); else return;
      }
      box.textContent = 'Your manager requested changes: ' + note;
    }

    
    
    
    function ttPopulateFromData(payload){
      if(!payload) return;

      // Set hidden timesheet ID
      const idField = document.getElementById('tt-timesheet-id');
      if(idField && payload.timesheet_id) idField.value = payload.timesheet_id;

      const days = payload.days || [];

      for(let i=0;i<14;i++){
        const row = days[i] || {};
        const in1  = row.in1  ?? row.time_in_1  ?? '';
        const out1 = row.out1 ?? row.time_out_1 ?? '';
        const in2  = row.in2  ?? row.time_in_2  ?? '';
        const out2 = row.out2 ?? row.time_out_2 ?? '';

        // All PTO selects use toFixed(1) option values ("0.0","0.5","1.0"…).
        // PHP floatval() returns bare floats (0, 0.5, 1…) which don't match those
        // strings, causing selects to silently reset to 0. Normalise all PTO values
        // to one decimal place so option lookups always succeed.
        const vac  = parseFloat(row.vac  ?? row.vacation ?? 0).toFixed(1);
        const sick = parseFloat(row.sick ?? 0).toFixed(1);
        const pers = parseFloat(row.pers ?? row.personal ?? 0).toFixed(1);
        const hol  = parseFloat(row.hol  ?? row.holiday  ?? 0).toFixed(1);
        const unp  = parseFloat(row.unp  ?? row.unpaid   ?? 0).toFixed(1);
        const ot   = parseFloat(row.ot   ?? row.overtime ?? 0).toFixed(1);

        // Fill JS-rendered grid (class-based)
        const tRow = $('#time-grid .time-body tr').eq(i);
        if(tRow && tRow.length){
          tRow.find('.in1').val(in1);
          tRow.find('.out1').val(out1);
          tRow.find('.in2').val(in2);
          tRow.find('.out2').val(out2);
        }
        const pRow = $('#pto-grid .pto-body tr').eq(i);
        if(pRow && pRow.length){
          pRow.find('.vac').val(vac);
          pRow.find('.sick').val(sick);
          pRow.find('.pers').val(pers);
          pRow.find('.hol').val(hol);
          pRow.find('.unp').val(unp);
          pRow.find('.ot').val(ot);
        }

        // Also fill any name-based fields if present (legacy or nested)
        const set = (name, val) => {
          const el = document.querySelector('[name="'+name+'"]');
          if(el) el.value = (val ?? '');
        };

        // Legacy names (if present)
        set(`time_in_1_${i}`, in1);
        set(`time_out_1_${i}`, out1);
        set(`time_in_2_${i}`, in2);
        set(`time_out_2_${i}`, out2);
        set(`vacation_${i}`, vac);
        set(`sick_${i}`, sick);
        set(`personal_${i}`, pers);
        set(`holiday_${i}`, hol);
        set(`unpaid_${i}`, unp);
        set(`overtime_${i}`, ot);

        // Nested names (if present)
        set(`tt_data[days][${i}][in1]`, in1);
        set(`tt_data[days][${i}][out1]`, out1);
        set(`tt_data[days][${i}][in2]`, in2);
        set(`tt_data[days][${i}][out2]`, out2);
        set(`tt_data[days][${i}][vac]`, vac);
        set(`tt_data[days][${i}][sick]`, sick);
        set(`tt_data[days][${i}][pers]`, pers);
        set(`tt_data[days][${i}][hol]`, hol);
        set(`tt_data[days][${i}][unp]`, unp);
        set(`tt_data[days][${i}][ot]`, ot);
      }

      // Recompute totals after programmatic population
      if(typeof recalcAll === 'function'){
        recalcAll();
      }
    }

    function ttSetLocked(locked){
      // Disable inputs if locked
      document.querySelectorAll('#tt-container input, #tt-container select, #tt-container textarea').forEach(el => {
        if(el.name === 'employee' || el.id === 'pay-period') return; // allow navigation
        if(el.id === 'tt-timesheet-id') return;
        if(el.type === 'hidden') return;
        el.disabled = !!locked;
      });
      // Disable draft button if locked
      const draftBtn = document.querySelector('button[name="tt_action"][value="draft"]');
      if(draftBtn) draftBtn.disabled = !!locked;
    }

    function ttLoadMyTimesheet(payPeriod){
      if(!window.TT_AJAX || !TT_AJAX.ajax_url) return;
      if(!payPeriod) return;
      const data = new FormData();
      data.append('action','tt_get_my_timesheet');
      data.append('pay_period', payPeriod);
      const qsTid = ttGetParam('timesheet_id');
      const hiddenTid = (document.getElementById('tt-timesheet-id')||{}).value || '';
      const tid = qsTid || hiddenTid;
      if(tid) data.append('timesheet_id', tid);
      if(empUidField && empUidField.value) data.append('employee_uid', empUidField.value);
      data.append('nonce', TT_AJAX.nonce || '');

      fetch(TT_AJAX.ajax_url, {method:'POST', credentials:'same-origin', body:data})
        .then(r=>r.json())
        .then(res=>{
          if(!res){ ttSetStatus('Could not load saved timesheet.'); return; }
          if(!res.success){
            ttSetStatus('Could not load saved timesheet (' + ((res && res.data && res.data.message) ? res.data.message : 'error') + ').');
            console.warn('[OAC Time Tracker] AJAX load failed', res);
            return;
          }
          if(!res.data || !res.data.found){
            // new sheet
            const idField = document.getElementById('tt-timesheet-id');
            if(idField) idField.value='';
            ttSetLocked(false);
            ttSetStatus('');
            return;
          }
          const tsid = res.data.timesheet_id;
          const state = res.data.state || 'draft';
          const payload = res.data.data || {};
          payload.timesheet_id = tsid;
          ttPopulateFromData(payload);
          const locked = (state === 'submitted' || state === 'approved');
          ttSetLocked(locked);
          ttSetStatus(locked ? ('Status: '+state) : ('Status: '+state+' (editable)'));
          ttSetChangesNote(res.data.changes_note || '');
        })
        .catch(()=>{});
    }

    // On pay period change, load existing draft/submission for this user
    const pp = document.getElementById('pay-period');
    if(pp){
      pp.addEventListener('change', function(){
        const val = this.value;
        ttLoadMyTimesheet(val);
      });
    }

    // On employee change (admins/managers), blank the grid and load that employee's sheet.
    if(empSelect && empSelect.tagName === 'SELECT'){
      empSelect.addEventListener('change', function(){
        ttSyncEmployeeUid();
        buildGrids(sel.val());
        const idField = document.getElementById('tt-timesheet-id');
        if(idField) idField.value = '';
        ttSetLocked(false);
        ttSetStatus('');
        ttLoadMyTimesheet(sel.val());
      });
    }

    // ── Clear Form ──────────────────────────────────────────────────────────
    // Rebuilds both grids with blank inputs for the currently selected period,
    // resets the hidden timesheet ID and status text, and unchecks the certify
    // checkbox. Does NOT delete anything from the server — it only clears the
    // on-screen form so the user can start fresh before saving or submitting.
    function ttClearForm(){
      const currentPeriod = sel.val();
      if(!currentPeriod) return;

      // Rebuild grids blank (buildGrids empties both tbodies before filling)
      buildGrids(currentPeriod);

      // Clear the hidden timesheet ID so a Save Draft creates a new record
      // rather than overwriting the existing one
      const idField = document.getElementById('tt-timesheet-id');
      if(idField) idField.value = '';

      // Re-enable all inputs in case the sheet was locked
      ttSetLocked(false);

      // Clear status text
      ttSetStatus('');

      // Uncheck certification checkbox and hide any warning
      const certify = document.getElementById('tt-employee-certify');
      if(certify) certify.checked = false;
      const warning = document.getElementById('tt-certify-warning');
      if(warning) warning.style.display = 'none';

      // Show certify block in case it was hidden
      const certBlock = document.getElementById('tt-certify-block');
      if(certBlock) certBlock.style.display = '';
    }

    const clearBtn = document.getElementById('tt-clear-btn');
    if(clearBtn){
      clearBtn.addEventListener('click', function(){
        if(confirm('Clear all entered hours and start a blank form for this pay period?\n\nThis will not delete anything already saved — it only clears what you see on screen.')){
          ttClearForm();
        }
      });
    }
    // ────────────────────────────────────────────────────────────────────────

    // Support deep links: ?pay_period=YYYY-MM-DD&timesheet_id=123
    // Ensure pay_period is present in dropdown even if outside the 8-period window
    function ttEnsurePayPeriodOption(val){
      if(!val) return;
      const pp = document.getElementById('pay-period');
      if(!pp) return;
      for(const opt of pp.options){ if(opt.value === val) return; }
      try{
        const dt = parseYMD(val);
        const en = addDays(dt, 13);
        const txt = `${dt.getMonth()+1}/${dt.getDate()}/${dt.getFullYear()} – ${en.getMonth()+1}/${en.getDate()}/${en.getFullYear()}`;
        const opt = document.createElement('option');
        opt.value = val;
        opt.textContent = txt;
        pp.insertBefore(opt, pp.firstChild);
      }catch(e){
        const opt = document.createElement('option');
        opt.value = val;
        opt.textContent = val;
        pp.insertBefore(opt, pp.firstChild);
      }
    }

    const qp = ttGetParam('pay_period');
    if(qp && pp){
      ttEnsurePayPeriodOption(qp);
      pp.value = qp;
      pp.dispatchEvent(new Event('change'));
    }

});
})(jQuery);
