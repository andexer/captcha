# Rutas del captcha — Symfony.
# Symfony 5.3 o superior importa config/routes/* solo, así que esta ruta entra
# sin tocar nada; solo si has desactivado ese glob, añade en config/routes.yaml:
#
#     captcha:
#         resource: 'routes/captcha.yaml'
#
# El controlador es un servicio con autowiring: solo necesita ser public en
# config/services.yaml para que esta ruta pueda referenciarlo.

captcha_generate:
    path: /captcha/generate
    controller: App\Controller\CaptchaController::generate
    methods: [GET]

# El widget no apunta aquí por defecto, así que decláralo al dibujarlo:
#
#     Captcha::widget(['endpoint' => '/captcha/generate']);
