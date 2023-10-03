Api que consume la api sii para el envío de boletas https://www4c.sii.cl/bolcoreinternetui/api/

# Diagrama de la DB

![db_model](https://github.com/aaguirreu/SiiApi/assets/64426866/ba1f5ea9-2e83-4aee-9c80-f5320e5b652b)

# Instrucciones

### php.ini

En el archivo php.ini quitar los punto y coma ";" de los siguientes líneas:
- extension=pdo_pgsql
- extension=pgsql
- extension=soap

### .env

 Copiar o reemplazar .env_example como .env  
`cp .env_example .env`

Llenar los datos faltantes:

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
### Migrar la base de datos

`php artisan migrate`

