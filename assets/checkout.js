jQuery(function ($) {
    var saveTimer = null;
    var lastSaved = '';
    // ── Helper: cookie reader ──────────────────────────────────────────────
    function getCookie(name) {
        var match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
        return match ? decodeURIComponent(match[2]) : '';
    }
    // ── Capture FBP + FBC and send to session ─────────────────────────────
    function saveFbCookies() {
        var fbp = getCookie('_fbp');
        var fbc = getCookie('_fbc');
        // If no FBC in cookie, try URL (ad click)
        if (!fbc) {
            try {
                var params = new URLSearchParams(window.location.search);
                var fbclid = params.get('fbclid');
                if (fbclid) fbc = 'fb.1.' + Date.now() + '.' + fbclid;
            } catch(e) {}
        }
        if (!fbp && !fbc) return;
        jQuery.post(ofm_ajax.ajax_url, {
            action : 'ofm_save_fb_cookies',
            nonce  : ofm_ajax.nonce,
            fbp    : fbp || '',
            fbc    : fbc || '',
            ua     : navigator.userAgent || '',
        });
    }
    // Run on page load
    saveFbCookies();
    // ── Capture billing info → save incomplete order ───────────────────────
    function maybeSave() {
        var fname = $('#billing_first_name').val() || '';
        var lname = $('#billing_last_name').val()  || '';
        var name  = (fname + ' ' + lname).trim();
        var phone = $('#billing_phone').val()   || '';
        var email = $('#billing_email').val()   || '';
        var addr  = [
            $('#billing_address_1').val() || '',
            $('#billing_address_2').val() || '',
            $('#billing_city').val()      || '',
            $('#billing_state').val()     || '',
        ].filter(Boolean).join(', ');
        if (!name || phone.replace(/[^0-9]/g,'').length < 10) return;
        var key = name + '|' + phone + '|' + email + '|' + addr;
        if (key === lastSaved) return;
        lastSaved = key;
        jQuery.post(ofm_ajax.ajax_url, {
            action  : 'ofm_save_incomplete',
            nonce   : ofm_ajax.nonce,
            name    : name,
            phone   : phone,
            email   : email,
            address : addr,
        });
    }
    var fields = [
        '#billing_first_name','#billing_last_name','#billing_phone',
        '#billing_email','#billing_address_1','#billing_address_2',
        '#billing_city','#billing_state'
    ].join(', ');
    $(document).on('input', fields, function () {
        clearTimeout(saveTimer);
        saveTimer = setTimeout(maybeSave, 1500);
    });
    $(document).on('blur', '#billing_phone, #billing_email', maybeSave);
});
