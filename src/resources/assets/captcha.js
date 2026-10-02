(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.Captcha = factory();
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    var VERSION = '1.0.0';
    var TIMEOUT = 8000;

    var MESSAGES = {
        reloadError: 'No se pudo recargar el captcha.',
    };

    /**
     * Wire-up para cada widget [data-captcha].
     * Solo recarga la imagen (modo servidor); la verificación ocurre
     * en el POST clásico del formulario.
     */
    function Widget(element) {
        this.el = element;
        this.endpoint = element.getAttribute('data-endpoint') || '';
        this.img = element.querySelector('.ct__image');
        this.reload = element.querySelector('.ct__reload');
        this.input = element.querySelector('.ct__input');
        this.hiddenId = element.querySelector('input[type="hidden"]');
        this.error = element.querySelector('.ct__error');
        this.reloading = false;
        this.bind();
    }

    function withAction(endpoint) {
        var glue = endpoint.indexOf('?') === -1 ? '?' : '&';
        return endpoint + glue + 'action=generate';
    }

    Widget.prototype.bind = function () {
        var self = this;

        if (this.reload) {
            this.reload.addEventListener('click', function (e) {
                e.preventDefault();
                self.refresh();
            });
        }

        if (this.img) {
            this.img.style.cursor = 'pointer';
            this.img.addEventListener('click', function () {
                self.refresh();
            });
        }
    };

    Widget.prototype.refresh = function () {
        if (this.reloading) {
            return;
        }

        /*
        *  Sin endpoint servido (instalación mínima): la recarga se resuelve
        *  re-renderizando la página, así el widget sigue siendo utilizable
        *  out-of-the-box sin copiar ningún archivo.
        */
        if (!this.endpoint) {
            this.reloadPage();

            return;
        }

        /*
        *  Sin fetch (navegadores antiguos): la recarga se resuelve
        *  re-renderizando la página, igual que si no hubiera endpoint.
        */
        if (typeof fetch !== 'function') {
            this.reloadPage();

            return;
        }

        this.reloading = true;
        this.setBusy(true);
        this.clearError();

        var self = this;
        var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
        var timer = controller ? setTimeout(function () {
            controller.abort();
        }, TIMEOUT) : null;

        var options = {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
        };
        if (controller) {
            options.signal = controller.signal;
        }

        fetch(withAction(this.endpoint), options)
            .then(function (response) {
                return response.json().then(
                    function (data) {
                        if (!response.ok) {
                            if (data && data.error) {
                                /*
                                *  El endpoint respondió: error real (p. ej.
                                *  rate limit 429) con mensaje en español;
                                *  no se recarga la página porque eso no lo
                                *  resolvería.
                                */
                                throw { serverError: data.error };
                            }
                            /*
                            *  Respuesta sin JSON útil: endpoint ausente o roto.
                            */
                            throw { endpointMissing: true };
                        }
                        return data;
                    },
                    function () {
                        /*
                        *  El body no es JSON (p. ej. 404 del servidor web).
                        */
                        throw { endpointMissing: true };
                    },
                );
            })
            .then(function (data) {
                if (!data.ok || !data.image || !data.id) {
                    throw { endpointMissing: true };
                }

                if (self.hiddenId) {
                    self.hiddenId.value = data.id;
                }
                if (self.input) {
                    self.input.value = '';
                    self.input.focus();
                }

                if (!self.img) {
                    self.reloading = false;
                    self.setBusy(false);

                    return;
                }

                var settle = function () {
                    self.reloading = false;
                    self.setBusy(false);
                };
                self.img.addEventListener('load', settle, { once: true });
                self.img.addEventListener('error', settle, { once: true });
                self.img.src = data.image;
            })
            .catch(function (reason) {
                self.reloading = false;
                self.setBusy(false);

                if (reason && reason.serverError) {
                    self.showError(reason.serverError);

                    return;
                }

                /*
                *  Endpoint ausente, red caída o cuerpo ilegible: se recarga
                *  la página para emitir un captcha nuevo (último recurso).
                */
                self.reloadPage();
            })
            .then(function () {
                if (timer) {
                    clearTimeout(timer);
                }
            });
    };

    Widget.prototype.showError = function (message) {
        if (this.error) {
            this.error.textContent = message;
        }
    };

    Widget.prototype.clearError = function () {
        if (this.error) {
            this.error.textContent = '';
        }
    };

    /*
    *  Último recurso sin endpoint: se vuelve a renderizar la página, lo que
    *  genera un captcha nuevo en el servidor (out-of-the-box en el estado más
    *  mínimo posible).
    */
    Widget.prototype.reloadPage = function () {
        if (typeof window !== 'undefined' && typeof window.location !== 'undefined') {
            window.location.reload();

            return;
        }
        this.showError(MESSAGES.reloadError);
    };

    Widget.prototype.setBusy = function (busy) {
        if (busy) {
            this.el.setAttribute('data-busy', 'true');
            this.el.setAttribute('aria-busy', 'true');
        } else {
            this.el.removeAttribute('data-busy');
            this.el.removeAttribute('aria-busy');
        }
        if (this.reload) {
            this.reload.disabled = busy;
        }
        if (this.input) {
            this.input.readOnly = busy;
        }
    };

    function autoInit() {
        var nodes = document.querySelectorAll('[data-captcha]');
        for (var i = 0; i < nodes.length; i++) {
            if (!nodes[i].__captcha) {
                nodes[i].__captcha = new Widget(nodes[i]);
            }
        }
    }

    /*
    *  El guard document/window mantiene el UMD seguro en Node (module.exports):
    *  sin DOM no hay widgets que inicializar, y ni document ni window existen
    *  allí (en entornos de servidor que definan document sin window, tampoco
    *  debe fallar la inicialización). reloadPage() aplica el mismo criterio.
    */
    if (typeof document !== 'undefined' && typeof window !== 'undefined') {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', autoInit);
        } else {
            autoInit();
        }
        document.addEventListener('load', autoInit);
        window.addEventListener('load', autoInit);
    }

    return {
        version: VERSION,
        Widget: Widget,
        autoInit: autoInit,
    };
}));
