
document.addEventListener('DOMContentLoaded', function () {

    // ========================================
    // SHOW / HIDE PASSWORD
    // ========================================

    const passwordInput = document.getElementById('password');
    const togglePassword = document.getElementById('togglePassword');

    if (passwordInput && togglePassword) {

        togglePassword.addEventListener('click', function () {

            const isPassword =
                passwordInput.type === 'password';

            passwordInput.type = isPassword
                ? 'text'
                : 'password';

            togglePassword.textContent = isPassword
                ? 'Sembunyikan'
                : 'Tampilkan';

            togglePassword.setAttribute(
                'aria-label',
                isPassword ? 'Sembunyikan password' : 'Tampilkan password'
            );

            togglePassword.setAttribute(
                'aria-pressed',
                String(isPassword)
            );
        });
    }

    // ========================================
    // LOGIN LOADING
    // ========================================

    const loginForm = document.getElementById('loginForm');
    const loginButton = document.getElementById('loginButton');

    if (loginForm && loginButton) {

        loginForm.addEventListener('submit', function (event) {

            if (!loginForm.checkValidity()) {
                return;
            }

            loginButton.disabled = true;
            loginButton.classList.add('is-loading');
        });
    }

    // ========================================
    // SALES REPORT PERIOD
    // ========================================

    const reportForm = document.querySelector('.report-filter-form');
    const reportPeriod = document.getElementById('report-period');
    const reportValue = document.getElementById('report-value');

    if (reportForm && reportPeriod && reportValue) {
        const periodInputs = reportForm.querySelectorAll('.report-period-input');

        const updatePeriodInput = function () {
            let activeInput = null;
            periodInputs.forEach(function (input) {
                const isActive = input.dataset.period === reportPeriod.value;
                input.disabled = !isActive;
                input.required = isActive;
                if (isActive) {
                    input.name = 'value';
                    activeInput = input;
                } else {
                    input.removeAttribute('name');
                }
            });
            reportValue.disabled = Boolean(activeInput);
        };

        reportPeriod.addEventListener('change', updatePeriodInput);
        updatePeriodInput();
    }

    // ========================================
    // CASHIER LIVE TOTAL AND CHANGE
    // ========================================

    const cashierForm = document.querySelector('.cashier-checkout-form');
    if (cashierForm) {
        const quantityInputs = cashierForm.querySelectorAll('.cashier-quantity-input');
        const receivedInput = cashierForm.querySelector('#uang_diterima');
        const itemCount = cashierForm.querySelector('[data-cashier-items]');
        const totalOutput = cashierForm.querySelector('[data-cashier-total]');
        const receivedOutput = cashierForm.querySelector('[data-cashier-received]');
        const changeOutput = cashierForm.querySelector('[data-cashier-change]');
        const messageOutput = cashierForm.querySelector('[data-cashier-message]');
        const submitButton = cashierForm.querySelector('.cashier-submit-button');
        const currencyFormatter = new Intl.NumberFormat('id-ID', {
            maximumFractionDigits: 0
        });

        const formatRupiah = function (cents) {
            return 'Rp ' + currencyFormatter.format(Math.floor(cents / 100));
        };

        const updateCashierTotals = function () {
            let totalCents = 0;
            let items = 0;

            quantityInputs.forEach(function (input) {
                const quantity = Math.max(0, Number.parseInt(input.value, 10) || 0);
                const priceCents = Number.parseInt(input.dataset.priceCents, 10) || 0;
                const subtotalCents = priceCents * quantity;
                const subtotalOutput = document.getElementById(input.dataset.subtotalTarget);

                totalCents += subtotalCents;
                items += quantity;
                if (subtotalOutput) {
                    subtotalOutput.dataset.cents = String(subtotalCents);
                    subtotalOutput.textContent = formatRupiah(subtotalCents);
                }
            });

            const receivedValue = receivedInput.value.trim();
            const receivedCents = receivedValue !== '' && /^\d+(?:\.\d{1,2})?$/.test(receivedValue)
                ? Math.round(Number(receivedValue) * 100)
                : 0;
            const changeCents = Math.max(0, receivedCents - totalCents);

            itemCount.textContent = currencyFormatter.format(items);
            totalOutput.textContent = formatRupiah(totalCents);
            receivedOutput.textContent = formatRupiah(receivedCents);
            changeOutput.textContent = formatRupiah(changeCents);

            const paymentSufficient = receivedCents >= totalCents && totalCents > 0;
            messageOutput.textContent = totalCents === 0
                ? 'Pilih produk untuk menghitung total.'
                : receivedCents < totalCents
                    ? 'Uang diterima masih kurang ' + formatRupiah(totalCents - receivedCents) + '.'
                    : 'Pembayaran cukup. Kembalian ' + formatRupiah(changeCents) + '.';
            messageOutput.classList.toggle('is-error', totalCents > 0 && receivedCents < totalCents);
            messageOutput.classList.toggle('is-success', paymentSufficient);
            submitButton.disabled = !paymentSufficient || !cashierForm.checkValidity();
        };

        quantityInputs.forEach(function (input) {
            input.addEventListener('input', updateCashierTotals);
            input.addEventListener('change', updateCashierTotals);
        });
        receivedInput.addEventListener('input', updateCashierTotals);
        updateCashierTotals();
    }

    // ========================================
    // THERMAL RECEIPT PRINT SIZE
    // ========================================

    const receiptPage = document.querySelector('.receipt-page[data-printable="true"]');
    const printReceiptButton = document.querySelector('[data-print-receipt]');

    if (receiptPage && printReceiptButton) {
        let printStyle = null;
        let printPending = false;

        const clearPrintLayout = function () {
            receiptPage.classList.remove('cashier-print-mode');
            document.body.classList.remove('cashier-print-mode');
            if (printStyle) {
                printStyle.remove();
                printStyle = null;
            }
            printPending = false;
        };

        printReceiptButton.addEventListener('click', function () {
            if (printPending) {
                return;
            }

            printPending = true;
            receiptPage.classList.add('cashier-print-mode');
            document.body.classList.add('cashier-print-mode');

            const receipt = receiptPage.querySelector('.cashier-receipt');
            if (!receipt) {
                clearPrintLayout();
                return;
            }

            const heightMillimeters = Math.ceil(
                (receipt.getBoundingClientRect().height * 25.4 / 96 + 1) * 10
            ) / 10;

            printStyle = document.createElement('style');
            printStyle.dataset.cashierPrintSize = 'true';
            printStyle.textContent = '@page { size: 50mm ' + heightMillimeters + 'mm; margin: 0; }';
            document.head.appendChild(printStyle);
            window.print();
        });

        window.addEventListener('afterprint', clearPrintLayout);
    }

    // ========================================
    // PRODUCT IMAGE PREVIEW
    // ========================================

    document.querySelectorAll('.product-image-editor').forEach(function (editor) {
        const fileInput = editor.querySelector('.product-image-input');
        const preview = editor.querySelector('.product-image-preview');
        const removeButton = editor.querySelector('.product-image-remove');
        const removeInput = editor.querySelector('[name="hapus_gambar"]');
        const fileName = editor.querySelector('.product-image-filename');
        const selectLabel = editor.querySelector('.product-image-select');

        if (!fileInput || !preview || !removeButton || !removeInput || !fileName) {
            return;
        }

        let previewUrl = null;
        const defaultSrc = editor.dataset.defaultSrc;
        const hasCurrentImage = editor.dataset.currentImage === '1';

        fileInput.addEventListener('change', function () {
            const file = fileInput.files && fileInput.files[0];
            if (!file) {
                return;
            }
            if (file.size > 2 * 1024 * 1024) {
                fileInput.value = '';
                fileName.textContent = 'Ukuran gambar melebihi 2 MB.';
                return;
            }
            if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
                fileInput.value = '';
                fileName.textContent = 'Pilih file JPG, JPEG, PNG, atau WEBP.';
                return;
            }

            if (previewUrl) {
                URL.revokeObjectURL(previewUrl);
            }
            previewUrl = URL.createObjectURL(file);
            preview.src = previewUrl;
            fileName.textContent = file.name;
            removeInput.value = '0';
            removeButton.hidden = !hasCurrentImage;
            if (selectLabel) {
                selectLabel.textContent = 'Ganti Gambar';
            }
        });

        removeButton.addEventListener('click', function () {
            fileInput.value = '';
            if (previewUrl) {
                URL.revokeObjectURL(previewUrl);
                previewUrl = null;
            }
            preview.src = defaultSrc;
            fileName.textContent = 'Gambar dihapus · JPG, JPEG, PNG, WEBP · Maksimal 2 MB';
            removeInput.value = hasCurrentImage ? '1' : '0';
            removeButton.hidden = true;
            if (selectLabel) {
                selectLabel.textContent = 'Pilih Gambar';
            }
        });
    });

});
