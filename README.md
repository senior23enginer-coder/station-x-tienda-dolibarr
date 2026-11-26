# Station X | Tienda y Dolibarr

La tienda PHP corre en la raiz web y consulta el catalogo y las existencias de Dolibarr. Dolibarr se publica bajo /htdocs. Este repositorio contiene ambos componentes, con el codigo oficial de Dolibarr fijado como submodulo.

## Preparacion local

1. Ejecuta git submodule update --init desde la raiz del repositorio.
2. Usa dolibarr/htdocs como fuente de la instalacion de Dolibarr. Conserva /htdocs como ruta publicada y crea su conf/conf.php mediante el instalador y una base propia.
3. Copia store-private.php.example a store-private.php y configura las variables requeridas en el entorno PHP. El archivo local queda ignorado.
4. Usa permisos de escritura para assets/uploads cuando corresponda y configura el directorio de documentos de Dolibarr fuera de la raiz publica.

## Procedencia

La tienda proviene de la copia de trabajo local de Station X. Los archivos assets/store.css y assets/store.js coinciden byte a byte con la publicacion consultada el 26 de noviembre de 2026. La pagina de acceso a /htdocs publica la version 22.0.4 en las URL de sus recursos. El submodulo apunta al commit de la etiqueta oficial 22.0.4. La configuracion local disponible indica el servidor, el usuario y el puerto FTPS. Para verificar el PHP servido y las posibles personalizaciones o modulos locales de Dolibarr se necesita acceso de lectura al hosting.

## Guía del código fuente

- [index.php](./index.php) y [producto.php](./producto.php) sirven el catálogo y las fichas de producto.
- [carrito.php](./carrito.php) gestiona la compra y [admin-store.php](./admin-store.php) ofrece la administración de la tienda.
- [store-lib.php](./store-lib.php) contiene la integración PHP con Dolibarr y servicios compartidos.
- [dolibarr](./dolibarr) referencia la versión oficial **22.0.4**.

### Verificación local

Ejecuta php -l index.php y node --check assets/store.js para comprobar la sintaxis. Para probar el catálogo y los pedidos, configura una instalación de Dolibarr de prueba con una base de datos separada.

### Sitios de referencia

- [Tienda Station X](https://stationxtulua.com/)
- [Dolibarr en Station X](https://stationxtulua.com/htdocs/)
