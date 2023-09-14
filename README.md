Api que consume la api sii para el envío de boletas https://www4c.sii.cl/bolcoreinternetui/api/

# Cómo utilizar

### Rellenar los valores de env_example 
CERT_PATH=Ruta a la firma digital (archivo .pfx o .p12)
CERT_PASS=Contraseña de la firma
FOLIOS_PATH=Carpeta donde se guardarán los cafs.xml
DTES_PATH=Carpeta donde se guardarán los dte.xml

### También rellenar los de la base de datos y luego migrarla.
php artisan migrate
