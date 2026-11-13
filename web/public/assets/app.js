(function () {
    'use strict';

    document.documentElement.classList.add('js');

    document.querySelectorAll('[data-autosubmit]').forEach(function (control) {
        var form = control.form;
        if (!form) {
            return;
        }
        control.addEventListener('change', function () {
            form.submit();
        });
    });

    document.querySelectorAll('[data-arch-toggle]').forEach(function (button) {
        var meta = button.closest('.index__meta');
        if (!meta) {
            return;
        }

        button.hidden = false;
        meta.classList.add('is-collapsible');

        button.addEventListener('click', function () {
            var open = meta.classList.toggle('is-open');
            button.setAttribute('aria-expanded', open ? 'true' : 'false');
            button.setAttribute(
                'aria-label',
                open ? button.dataset.labelLess : button.dataset.labelMore
            );
        });
    });

    var input = document.getElementById('q');
    if (input) {
        document.addEventListener('keydown', function (event) {
            var tag = (event.target.tagName || '').toLowerCase();
            var typing = tag === 'input' || tag === 'textarea' || event.target.isContentEditable;
            if (event.key === '/' && !typing) {
                event.preventDefault();
                input.focus();
                input.select();
            }
        });
    }

    document.querySelectorAll('[data-carousel]').forEach(function (carousel) {
        var track = carousel.querySelector('[data-carousel-track]');
        var slides = Array.prototype.slice.call(carousel.querySelectorAll('[data-carousel-slide]'));
        if (!track || !slides.length) {
            return;
        }

        var prev = carousel.querySelector('[data-carousel-prev]');
        var next = carousel.querySelector('[data-carousel-next]');
        var dots = carousel.querySelector('[data-carousel-dots]');
        var counter = carousel.querySelector('[data-carousel-counter]');
        var lightbox = document.querySelector('[data-lightbox]');
        var lightboxImage = lightbox ? lightbox.querySelector('[data-lightbox-image]') : null;
        var lightboxCounter = lightbox ? lightbox.querySelector('[data-lightbox-counter]') : null;
        var lightboxPrev = lightbox ? lightbox.querySelector('[data-lightbox-prev]') : null;
        var lightboxNext = lightbox ? lightbox.querySelector('[data-lightbox-next]') : null;
        var lightboxZoom = lightbox ? lightbox.querySelector('[data-lightbox-zoom]') : null;
        var lightboxClose = lightbox ? lightbox.querySelector('[data-lightbox-close]') : null;
        var index = 0;
        var buttons = [];
        var lastFocus = null;
        var zoomed = false;

        function pad(value) {
            return value < 10 ? '0' + value : String(value);
        }

        if (dots) {
            slides.forEach(function (_, position) {
                var button = document.createElement('button');
                button.type = 'button';
                button.className = 'carousel__dot';
                button.setAttribute('aria-label', 'Image ' + (position + 1) + ' sur ' + slides.length);
                button.addEventListener('click', function () {
                    go(position);
                });
                dots.appendChild(button);
                buttons.push(button);
            });
        }

        function update() {
            track.style.transform = 'translateX(' + (-index * 100) + '%)';
            slides.forEach(function (slide, position) {
                var active = position === index;
                slide.setAttribute('aria-hidden', active ? 'false' : 'true');
                var image = slide.querySelector('img');
                if (image) {
                    image.tabIndex = active ? 0 : -1;
                }
            });
            buttons.forEach(function (button, position) {
                button.setAttribute('aria-current', position === index ? 'true' : 'false');
            });
            if (counter) {
                counter.textContent = pad(index + 1) + ' / ' + pad(slides.length);
            }
            if (lightbox && !lightbox.hidden) {
                renderLightbox();
            }
        }

        function fitLightboxImage() {
            if (!lightboxImage || !lightboxImage.naturalWidth || !lightboxImage.naturalHeight) {
                return;
            }

            var compact = window.innerWidth <= 800;
            var stage = lightboxImage.parentElement;
            var stageWidth = stage ? stage.clientWidth : window.innerWidth;
            var maxWidth = Math.max(120, stageWidth - (compact ? 24 : 128));
            var maxHeight = window.innerHeight * (compact ? 0.72 : 0.78);
            var ratio = Math.min(
                maxWidth / lightboxImage.naturalWidth,
                maxHeight / lightboxImage.naturalHeight,
                zoomed ? 3 : 1
            );

            lightboxImage.style.width = Math.round(lightboxImage.naturalWidth * ratio) + 'px';
            lightboxImage.style.height = Math.round(lightboxImage.naturalHeight * ratio) + 'px';
        }

        function setZoomed(value) {
            zoomed = Boolean(value);
            if (lightboxZoom) {
                lightboxZoom.setAttribute('aria-pressed', zoomed ? 'true' : 'false');
                lightboxZoom.textContent = zoomed ? 'Taille réelle' : 'Plein écran';
            }
            fitLightboxImage();
        }

        function renderLightbox() {
            var image = slides[index].querySelector('img');
            if (!image || !lightboxImage) {
                return;
            }
            lightboxImage.src = image.currentSrc || image.src;
            lightboxImage.alt = image.alt || '';
            if (lightboxCounter) {
                lightboxCounter.textContent = pad(index + 1) + ' / ' + pad(slides.length);
            }
            if (lightboxImage.complete) {
                fitLightboxImage();
            }
        }

        function go(target) {
            index = (target + slides.length) % slides.length;
            update();
        }

        function openLightbox() {
            if (!lightbox || !lightboxImage) {
                return;
            }
            lastFocus = document.activeElement;
            setZoomed(false);
            renderLightbox();
            lightbox.hidden = false;
            document.body.classList.add('lightbox-open');
            fitLightboxImage();
            if (lightboxClose) {
                lightboxClose.focus();
            }
        }

        function closeLightbox() {
            if (!lightbox || lightbox.hidden) {
                return;
            }
            lightbox.hidden = true;
            document.body.classList.remove('lightbox-open');
            setZoomed(false);
            lightboxImage.removeAttribute('src');
            lightboxImage.style.width = '';
            lightboxImage.style.height = '';
            if (lastFocus && typeof lastFocus.focus === 'function') {
                lastFocus.focus();
            }
        }

        slides.forEach(function (slide) {
            var image = slide.querySelector('img');
            if (!image) {
                return;
            }
            image.addEventListener('click', function (event) {
                event.preventDefault();
                if (swiped) {
                    return;
                }
                openLightbox();
            });
            image.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    openLightbox();
                }
            });
        });

        if (prev) {
            prev.addEventListener('click', function () {
                go(index - 1);
            });
        }

        if (next) {
            next.addEventListener('click', function () {
                go(index + 1);
            });
        }

        carousel.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowLeft') {
                event.preventDefault();
                go(index - 1);
            } else if (event.key === 'ArrowRight') {
                event.preventDefault();
                go(index + 1);
            }
        });

        var startX = null;
        var swiped = false;
        carousel.addEventListener('touchstart', function (event) {
            if (event.touches.length === 1) {
                startX = event.touches[0].clientX;
                swiped = false;
            }
        }, { passive: true });

        carousel.addEventListener('touchend', function (event) {
            if (startX === null) {
                return;
            }
            var delta = event.changedTouches[0].clientX - startX;
            if (Math.abs(delta) > 40) {
                swiped = true;
                go(index + (delta < 0 ? 1 : -1));
                window.setTimeout(function () {
                    swiped = false;
                }, 400);
            }
            startX = null;
        }, { passive: true });

        if (lightbox) {
            if (lightboxImage) {
                lightboxImage.addEventListener('load', fitLightboxImage);
            }

            window.addEventListener('resize', function () {
                if (!lightbox.hidden) {
                    fitLightboxImage();
                }
            });

            lightbox.addEventListener('click', function (event) {
                if (event.target.closest('[data-lightbox-image]') ||
                    event.target.closest('[data-lightbox-prev]') ||
                    event.target.closest('[data-lightbox-next]') ||
                    event.target.closest('[data-lightbox-zoom]')) {
                    return;
                }
                closeLightbox();
            });

            if (lightboxZoom) {
                lightboxZoom.addEventListener('click', function () {
                    setZoomed(!zoomed);
                });
            }

            if (lightboxPrev) {
                lightboxPrev.addEventListener('click', function () {
                    go(index - 1);
                });
            }

            if (lightboxNext) {
                lightboxNext.addEventListener('click', function () {
                    go(index + 1);
                });
            }

            document.addEventListener('keydown', function (event) {
                if (lightbox.hidden) {
                    return;
                }

                if (event.key === 'Escape') {
                    event.preventDefault();
                    closeLightbox();
                } else if (event.key === 'ArrowLeft') {
                    event.preventDefault();
                    go(index - 1);
                } else if (event.key === 'ArrowRight') {
                    event.preventDefault();
                    go(index + 1);
                } else if (event.key === 'Tab') {
                    var focusables = Array.prototype.slice.call(lightbox.querySelectorAll('button'));
                    if (!focusables.length) {
                        return;
                    }
                    var first = focusables[0];
                    var last = focusables[focusables.length - 1];
                    if (event.shiftKey && document.activeElement === first) {
                        event.preventDefault();
                        last.focus();
                    } else if (!event.shiftKey && document.activeElement === last) {
                        event.preventDefault();
                        first.focus();
                    }
                }
            });
        }

        update();
    });
})();
