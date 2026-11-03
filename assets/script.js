// Developer: Alindevx00x (https://alindevx00x.me)
(() => {
    const menuButton = document.querySelector('.menu-toggle');
    const navigation = document.querySelector('#main-navigation');

    if (menuButton && navigation) {
        const closeMenu = () => {
            menuButton.setAttribute('aria-expanded', 'false');
            menuButton.setAttribute('aria-label', menuButton.dataset.openLabel || 'Open menu');
            navigation.classList.remove('is-open');
        };

        menuButton.addEventListener('click', () => {
            const isOpen = menuButton.getAttribute('aria-expanded') === 'true';
            menuButton.setAttribute('aria-expanded', String(!isOpen));
            menuButton.setAttribute('aria-label', isOpen ? (menuButton.dataset.openLabel || 'Open menu') : (menuButton.dataset.closeLabel || 'Close menu'));
            navigation.classList.toggle('is-open', !isOpen);
        });

        navigation.querySelectorAll('a').forEach((link) => link.addEventListener('click', closeMenu));
        document.addEventListener('click', (event) => {
            if (!navigation.contains(event.target) && !menuButton.contains(event.target)) closeMenu();
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') closeMenu();
        });
    }

    const arrival = document.querySelector('#arrival');
    const departure = document.querySelector('#departure');
    if (arrival && departure) {
        const updateDepartureMinimum = () => {
            departure.min = arrival.value || arrival.min;
            if (arrival.value && departure.value && departure.value <= arrival.value) {
                departure.value = '';
            }
        };
        arrival.addEventListener('change', updateDepartureMinimum);
        updateDepartureMinimum();
    }

    const whatsappLink = document.querySelector('.whatsapp-float');
    const guestsInput = document.querySelector('#guests');
    if (whatsappLink && arrival && departure) {
        const updateWhatsAppMsg = () => {
            const baseUrl = whatsappLink.dataset.baseUrl || 'https://wa.me/212600000000';
            const arr = arrival.value;
            const dep = departure.value;
            const guests = guestsInput ? guestsInput.value : '2';
            const isArabic = document.documentElement.dir === 'rtl';

            if (arr && dep) {
                let msg = '';
                if (isArabic) {
                    msg = `السلام عليكم دار إل أوليفار، أرغب في الاستفسار عن إمكانية حجز إقامة من ${arr} إلى ${dep} لعدد ${guests} نزلاء.`;
                } else {
                    msg = `Bonjour El Olivar, je souhaite réserver du ${arr} au ${dep} pour ${guests} personnes.`;
                }
                whatsappLink.href = `${baseUrl}?text=${encodeURIComponent(msg)}`;
            } else {
                whatsappLink.href = `${baseUrl}?text=${encodeURIComponent(whatsappLink.dataset.defaultMsg || '')}`;
            }
        };
        arrival.addEventListener('change', updateWhatsAppMsg);
        departure.addEventListener('change', updateWhatsAppMsg);
        if (guestsInput) guestsInput.addEventListener('change', updateWhatsAppMsg);
    }

    const inquiryForm = document.querySelector('.inquiry-form');
    if (inquiryForm) {
        inquiryForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const submitBtn = inquiryForm.querySelector('.inquiry-form__submit');
            const origContent = submitBtn ? submitBtn.innerHTML : '';
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.style.opacity = '0.7';
            }

            try {
                const formData = new FormData(inquiryForm);
                const response = await fetch(inquiryForm.action || 'index.php#contact', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    }
                });

                const data = await response.json();
                let notice = inquiryForm.querySelector('.form-notice');
                if (!notice) {
                    notice = document.createElement('div');
                    notice.className = 'form-notice';
                    inquiryForm.insertBefore(notice, inquiryForm.firstChild);
                }

                if (data.success) {
                    notice.className = 'form-notice form-notice--success';
                    notice.setAttribute('role', 'status');
                    notice.setAttribute('aria-live', 'polite');
                    notice.textContent = data.message;
                    if (data.new_csrf_token) {
                        const csrfInput = inquiryForm.querySelector('input[name="csrf_token"]');
                        if (csrfInput) csrfInput.value = data.new_csrf_token;
                    }
                    inquiryForm.reset();
                    const consent = inquiryForm.querySelector('input[name="consent"]');
                    if (consent) consent.checked = false;
                } else {
                    notice.className = 'form-notice form-notice--error';
                    notice.setAttribute('role', 'alert');
                    notice.setAttribute('aria-live', 'assertive');
                    notice.textContent = data.message || 'Une erreur est survenue.';
                }
            } catch (err) {
                inquiryForm.submit();
                return;
            } finally {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.style.opacity = '1';
                    submitBtn.innerHTML = origContent;
                }
            }
        });
    }

    const tiles = Array.from(document.querySelectorAll('.gallery-tile'));
    const lightbox = document.querySelector('.lightbox');
    if (!tiles.length || !lightbox || typeof lightbox.showModal !== 'function') return;

    const lightboxImage = lightbox.querySelector('figure img');
    const lightboxCaption = lightbox.querySelector('figcaption');
    let activeIndex = 0;
    let previousFocus = null;

    const showImage = (index) => {
        activeIndex = (index + tiles.length) % tiles.length;
        const tile = tiles[activeIndex];
        lightboxImage.src = tile.dataset.full;
        lightboxImage.alt = tile.dataset.alt || '';
        lightboxCaption.textContent = tile.dataset.alt || '';
    };

    tiles.forEach((tile, index) => {
        tile.addEventListener('click', () => {
            previousFocus = tile;
            showImage(index);
            lightbox.showModal();
            lightbox.querySelector('.lightbox__close').focus();
        });
    });

    lightbox.querySelector('.lightbox__close').addEventListener('click', () => lightbox.close());
    lightbox.querySelector('.lightbox__previous').addEventListener('click', () => showImage(activeIndex - 1));
    lightbox.querySelector('.lightbox__next').addEventListener('click', () => showImage(activeIndex + 1));
    lightbox.addEventListener('click', (event) => {
        if (event.target === lightbox) lightbox.close();
    });
    lightbox.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowLeft') showImage(activeIndex - 1);
        if (event.key === 'ArrowRight') showImage(activeIndex + 1);
    });
    lightbox.addEventListener('close', () => previousFocus?.focus());
})();
