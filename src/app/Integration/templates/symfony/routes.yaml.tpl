# Rutas del captcha — Symfony.
# El controlador es un servicio con autowiring: solo necesita ser public en
# config/services.yaml para que esta ruta pueda referenciarlo.
#
# Importa el fichero desde config/routes.yaml:
#
#     captcha:
#         resource: 'routes/captcha.yaml'

captcha_generate:
    path: /captcha/generate
    controller: App\Controller\CaptchaController::generate
    methods: [GET]
