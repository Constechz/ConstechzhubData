/**
 * Lightweight Font Awesome loader with graceful fallback
 *  - Prefers the bundled local assets (assets/vendor/fontawesome)
 *  - Falls back to public CDNs only when necessary
 *  - Adds body classes so CSS can adapt (fa-loading, fa-ready, fa-fallback)
 */
(function () {
    'use strict';

    // Capture the loader script URL once so relative paths stay correct after DOM ready.
    const resolveScriptBase = () => {
        if (document.currentScript && document.currentScript.src) {
            return document.currentScript.src;
        }
        const scripts = document.getElementsByTagName('script');
        for (let i = scripts.length - 1; i >= 0; i--) {
            const src = scripts[i].src || '';
            if (src.indexOf('font-awesome-loader.js') !== -1) {
                return src;
            }
        }
        return window.location.href;
    };

    const loaderBaseHref = resolveScriptBase();

    class FontAwesomeLoader {
        constructor(baseHref) {
            this.baseUrl = baseHref;
            this.localCss = new URL('../vendor/fontawesome/css/all.min.css', this.baseUrl).href;
            this.cdnList = [
                'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css',
                'https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css',
                'https://use.fontawesome.com/releases/v6.5.1/css/all.css'
            ];
            this.isLoaded = false;
            this.tryIndex = 0;
            document.body.classList.add('fa-loading');
            this.loadStyles();
            this.monitorFonts();
        }

        loadStyles() {
            this.injectStylesheet(this.localCss)
                .then(() => this.markReady())
                .catch(() => this.loadFromCdn());
        }

        loadFromCdn() {
            if (this.tryIndex >= this.cdnList.length) {
                this.markFallback();
                return;
            }
            const url = this.cdnList[this.tryIndex++];
            this.injectStylesheet(url)
                .then(() => this.markReady())
                .catch(() => this.loadFromCdn());
        }

        injectStylesheet(url) {
            return new Promise((resolve, reject) => {
                if (document.querySelector(`link[data-fa-source="${url}"]`)) {
                    resolve();
                    return;
                }
                const link = document.createElement('link');
                link.rel = 'stylesheet';
                link.href = url;
                link.setAttribute('data-fa-source', url);
                link.onload = () => {
                    this.isLoaded = true;
                    resolve();
                };
                link.onerror = reject;
                document.head.appendChild(link);
            });
        }

        monitorFonts() {
            if (!('fonts' in document)) {
                // Older browsers: rely on load events only
                setTimeout(() => {
                    if (!this.isLoaded) {
                        this.markFallback();
                    }
                }, 4000);
                return;
            }

            document.fonts.ready
                .then(() => {
                    if (this.isLoaded) {
                        this.markReady();
                    } else {
                        this.markFallback();
                    }
                })
                .catch(() => this.markFallback());

            // Safety timeout
            setTimeout(() => {
                if (!this.isLoaded && !document.body.classList.contains('fa-fallback')) {
                    this.markFallback();
                }
            }, 5000);
        }

        markReady() {
            document.body.classList.add('fa-ready');
            document.body.classList.remove('fa-loading', 'fa-fallback');
        }

        markFallback() {
            document.body.classList.add('fa-fallback');
            document.body.classList.remove('fa-loading');
        }
    }

    const init = () => new FontAwesomeLoader(loaderBaseHref);
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
