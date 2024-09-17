# ApiDTELogiciel

ApiDTELogiciel permite para comunicarse con el Servicio de Impuestos Internos (SII) de Chile desde un Sistema de Facturación de Mercado.

## Características

- Firmar documentos electrónicos con certificado digital.
- Utiliza LibreDTE para generar y enviar documentos tributarios electrónicos (DTE) al SII. Por lo que, contiene todas las carácterísticas la librería.
- Generar PDF del DTE.
- Consultar estados de los DTE.
- Obtener Certificado de Autorización de Folios (CAF) desde el SII.
- Autenticación y autorización de usuarios con Laravel Sanctum.
- Laravel Pulse para monitoreo de la aplicación.

## Requerimientos (Debian 12)

- php-curl
- php-xml
- php-soap
- php-bcmath
- php-imagick
- libgdk-pixbuf-2.0-0
- libmagickwand-dev (Necesario para Logo SVG)
- Openssl (con Legacy:Opcional)

## Instalación
### VM con Apache
#### Clonar repositorio

```shell
cd /var/www/html/
git clone https://github.com/aaguirreu/SiiApi.git
```

#### Configurar .env

Copiar o reemplazar .env_example como .env

```shell
cp .env_example .env
```

Reemplazar los valores de la base de datos. Necesario para el envío de DTEs.

- DB_CONNECTION=pgsql
- DB_HOST=127.0.0.1
- DB_PORT=5432
- DB_DATABASE=postgres
- DB_USERNAME=postgres
- DB_PASSWORD=postgres

#### Instalar dependencias y migrar la base de datos

Se utiliza la flag --ignore-platform-reqs para instalar la última versión del paquete sasco/LibreDTE,
debido a que este requiere php7.4 y este proyecto utiliza php8.2.

```shell
composer install --no-dev --ignore-platform-reqs
php artisan migrate --seed
```

#### Configuración de permisos

Verifica que el usuario al cual la aplicación pertenece tenga permisos de escritura en las carpetas de logs y cache. Con los siguientes comandos puedes asignarle los permisos. Fíjate que no estés como root user, o reemplaza ${USER} por el usuario que la aplicación utiliza.

```shell
chown -R ${USER}:www-data .
chmod -R 774 storage/logs/
chmod -R 774 storage/framework/
php artisan cache:clear
```

#### Habilitar Legacy de OpenSSL

Necesario solo si certificados digitales a utilizar están cifrados con algoritmos obsoletos como SHA1. 
Instrucciones obtenidas de [Stackoverflow](https://stackoverflow.com/questions/73832854/php-openssl-pkcs12-read-error0308010cdigital-envelope-routinesunsupported).

```shell
nano /etc/ssl/openssl.cnf
```

Buscar [default_sect] y cambiar a:

[default_sect]
activate = 1
[legacy_sect]
activate = 1

Buscar [provider_sect] y cambiar a:

[provider_sect]
default = default_sect
legacy = legacy_sect

Guardar y reiniciar apache de ser necesario.

#### Cambiar la contraseña para obtener el token con Tinker

Es importante haber utilizado la flag --seed al migrar, ya que, genera el usuario.

```shell
php artisan tinker
$user = User::find(1);
$user->update(['password' => Hash::make('secret')]);
```

## Uso

Para utilizar la API, asegúrate de que el servidor esté en funcionamiento y realiza solicitudes a los endpoints definidos en el esquema Swagger (OpenAPI).

## Contribuciones

Las contribuciones son bienvenidas. Por favor, sigue estos pasos:

- [LibreDTE-Lib](https://github.com/LibreDTE/libredte-lib)

## Licencia

Este proyecto está bajo la Licencia MIT. Consulta el archivo `LICENSE` para más detalles.
