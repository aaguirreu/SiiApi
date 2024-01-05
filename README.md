Api que consume la api sii para el envío de boletas https://www4c.sii.cl/bolcoreinternetui/api/

# Diagrama de la DB

![db_model](https://github.com/aaguirreu/SiiApi/assets/64426866/ba1f5ea9-2e83-4aee-9c80-f5320e5b652b)

# Instrucciones
Sigue las siguientes instrucciones para un correcto funcionamiento en una maquina virtual con apache2.

### php.ini

En el archivo php.ini quitar los punto y coma ";" de los siguientes líneas:
- extension=pdo_pgsql
- extension=pgsql
- extension=soap
- extension=curl
- extension=zip

### Clonar repositorio

```
cd /var/www/html/
git clone https://github.com/aaguirreu/SiiApi.git
```

### .env

 Copiar o reemplazar .env_example como .env  
`cp .env_example .env`

Llenar los datos faltantes. Verifica que CAFS_PATH Y DTES_PATH terminen en /.

- CERT_PATH= Ruta a la firma digital (archivo .pfx o .p12).
- CERT_PASS= Contraseña de la firma
- CAFS_PATH= Carpeta donde se guardarán los cafs.xml
- DTES_PATH= Carpeta donde se guardarán los dte.xml

Reemplazar los valores de la base de datos según corresponda:

- DB_CONNECTION=pgsql
- DB_HOST=127.0.0.1
- DB_PORT=5432
- DB_DATABASE=postgres
- DB_USERNAME=postgres
- DB_PASSWORD=postgres

### Instalar dependencias y migrar la base de datos

`composer install --no-dev`
`php artisan migrate`

### Configurar dteimap:idle command para recibir los correos

En desarrollo se puede ejecutar el comando de forma manual:

`php artisan dteimap:idle`

Para que se ejecute en producción se debe configurar un cronjob que ejecute el comando cada cierto tiempo.
Más información en la página oficial de [PHP-IMAP](https://www.php-imap.com/frameworks/laravel/service)

#### Configurando un systemd service:

`nano /etc/systemd/system/imap-idle.service`

```
[Unit]
Description=ImapIdle
After=multi-user.target
After=syslog.target
After=network-online.target

[Service]
Type=simple

User=www-data
Group=www-data

WorkingDirectory=/var/www/my_project
ExecStart=/user/bin/php artisan dteimap:idle

Restart=on-failure
RestartSec=5s

[Install]
WantedBy=multi-user.target
```
```
systemctl start imap-idle.service
systemctl enable imap-idle.service
```

#### Configuración de permisos

Verifica que el usuario al cual la aplicación pertenece tenga permisos de escritura en las carpetas de logs y cache. Con los siguientes comandos puedes asignarle los permisos. Fíjate que no estés como root user, o reemplaza ${USER} por el usuario que la aplicación utiliza.
```
chown -R ${USER}:www-data .
chmod -R 774 storage/logs/
chmod -R 774 storage/framework/
php artisan cache:clear
```

### Obtener Token para consumir la API

Para esta api se utiliza Sanctum. Usando los siguientes comandos obtendrás el token para consumir la api.

```
php artisan migrate --seed
php artisan tinker
$user = User::find(1);
$token = $user->createToken('token')->plainTextToken;
```

El string que se imprima al final será tu token bearer.
