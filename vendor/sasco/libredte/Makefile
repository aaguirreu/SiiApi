SUBJECT=/C=CL/title=PERSONA NATURAL/CN=NOMBRE DEL USUARIO/emailAddress=user@example.com/serialNumber=11222333-4

all: composer firma test

composer:
	composer install

firma:
	openssl req -x509 -sha256 -nodes -days 1 -newkey rsa:2048 -keyout tests/firma.key -out tests/firma.crt -subj "$(SUBJECT)"
	openssl pkcs12 -export -out tests/firma.p12 -inkey tests/firma.key -in tests/firma.crt -passout pass:test
	rm tests/firma.key tests/firma.crt

test: composer firma
	vendor/bin/phpunit --bootstrap tests/bootstrap.php tests

pdf:
	doxygen && cd doc/latex && make pdf

clean:
	rm -rf tests/firma.p12 doc/html doc/latex examples/pdf examples/xml

latexbase:
	doxygen -w latex doc/doxygen/header.tex doc/doxygen/footer.tex doc/doxygen/doxygen.sty
