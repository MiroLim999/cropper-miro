/* Shared confirm dialog.  Usage:
 *   if (!(await askConfirm({ title, message, confirmText, cancelText, danger }))) return;
 * Resolves true on Confirm, false on Cancel / Esc / click outside. Enter confirms. */
(function () {
    let open = false;

    window.askConfirm = function (opts) {
        opts = opts || {};
        if (open) return Promise.resolve(false);
        open = true;

        return new Promise((resolve) => {
            const prevFocus = document.activeElement;
            const overlay = document.createElement('div');
            overlay.className = 'cf-overlay';
            overlay.setAttribute('role', 'dialog');
            overlay.setAttribute('aria-modal', 'true');

            const box = document.createElement('div');
            box.className = 'cf-box';

            const icon = document.createElement('div');
            icon.className = 'cf-icon' + (opts.danger ? ' danger' : '');
            icon.textContent = opts.danger ? '!' : '?';

            const title = document.createElement('h3');
            title.className = 'cf-title';
            title.textContent = opts.title || 'Are you sure?';

            const msg = document.createElement('p');
            msg.className = 'cf-msg';
            msg.textContent = opts.message || '';

            const row = document.createElement('div');
            row.className = 'cf-actions';
            const cancel = document.createElement('button');
            cancel.type = 'button';
            cancel.className = 'cf-btn';
            cancel.textContent = opts.cancelText || 'Cancel';
            const ok = document.createElement('button');
            ok.type = 'button';
            ok.className = 'cf-btn cf-ok' + (opts.danger ? ' danger' : '');
            ok.textContent = opts.confirmText || 'Confirm';
            row.append(cancel, ok);

            let chk = null;
            box.append(icon, title, msg);
            if (opts.checkbox) {
                const lab = document.createElement('label');
                lab.className = 'cf-check';
                chk = document.createElement('input');
                chk.type = 'checkbox';
                lab.append(chk, document.createTextNode(' ' + opts.checkbox));
                box.append(lab);
            }
            box.append(row);
            overlay.appendChild(box);
            document.body.appendChild(overlay);
            (opts.danger ? cancel : ok).focus();

            const done = (result) => {
                document.removeEventListener('keydown', onKey, true);
                window.askConfirm.checked = !!(chk && chk.checked);
                overlay.remove();
                open = false;
                if (prevFocus && prevFocus.focus) { try { prevFocus.focus(); } catch (e) {} }
                resolve(result);
            };
            const onKey = (e) => {
                if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); done(false); }
                else if (e.key === 'Enter') {
                    e.preventDefault(); e.stopPropagation();
                    done(document.activeElement === cancel ? false : true);
                } else if (e.key === 'Tab') {            // keep focus inside the dialog
                    e.preventDefault();
                    (document.activeElement === ok ? cancel : ok).focus();
                }
            };
            document.addEventListener('keydown', onKey, true);
            cancel.onclick = () => done(false);
            ok.onclick = () => done(true);
            overlay.addEventListener('mousedown', (e) => { if (e.target === overlay) done(false); });
        });
    };
})();
