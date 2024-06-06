Api que genera y envía XML firmados al SII y receptor. Utiliza LibreDTE

### Requerimientos
- php-curl
- php-xml
- php-bcmath
- php-imagick
- libgdk-pixbuf-2.0-0

## Instrucciones
Sigue las siguientes instrucciones para un correcto funcionamiento en una maquina virtual con apache2.

### Clonar repositorio

```
cd /var/www/html/
git clone https://github.com/aaguirreu/SiiApi.git
```

### Configurar .env

Copiar o reemplazar .env_example como .env  
`cp .env_example .env`

Reemplazar los valores de la base de datos. Necesario para el envío de DTEs.

- DB_CONNECTION=pgsql
- DB_HOST=127.0.0.1
- DB_PORT=5432
- DB_DATABASE=postgres
- DB_USERNAME=postgres
- DB_PASSWORD=postgres

### Instalar dependencias y migrar la base de datos
Se utiliza la flag --ignore-platform-reqs para instalar la última versión del paquete sasco/LibreDTE, 
debido a que este requiere php7.4 y este proyecto utiliza php8.2.

```
composer install --no-dev --ignore-platform-reqs
php artisan migrate --seed
```

#### Configuración de permisos

Verifica que el usuario al cual la aplicación pertenece tenga permisos de escritura en las carpetas de logs y cache. Con los siguientes comandos puedes asignarle los permisos. Fíjate que no estés como root user, o reemplaza ${USER} por el usuario que la aplicación utiliza.
```
chown -R ${USER}:www-data .
chmod -R 774 storage/logs/
chmod -R 774 storage/framework/
php artisan cache:clear
```

### Cambiar la contraseña para obtener el token con Tinker
Es importante haber utilizado la flag --seed al migrar, ya que, genera el usuario.
```
php artisan tinker
$user = User::find(1);
$user->update(['password' => Hash::make('secret')]);
```
