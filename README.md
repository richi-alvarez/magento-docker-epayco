# docker-lamp

Docker with Apache, MySQL 8.0, PHPMyAdmin and PHP.

I use docker-compose as an orchestrator. To run these containers:

```
docker-compose up -d
```
```
docker cp docker-lamp_www_1:/usr/local/etc/php ./config
```
```
cd config 
```
```
cp php.ini-production php.ini
```
```
docker-compose up --build
```
```
docker-compose exec www php -v
```
```
docker run --rm -v $(pwd):/app composer:1.10 install
```
```
sobre la ruta donde se encuentra laravel
docker-compose exec www php artisan key:generate
```
agregar al volumen de www. la nueva carpeta con los ajustes de php ./config:/usr/local/etc/php
Open phpmyadmin at [http://127.0.0.1:8000](http://127.0.0.1:8000)
Open web browser to look at a simple php example at [http://127.0.0.1:80](http://127.0.0.1:80)

Clone YourProject on `www/` and then, open web [http://127.0.0.1/YourProject](http://127.0.0.1/YourProject)

Run MySQL client:

- `docker-compose exec db mysql -u root -p` 

Infrastructure as code!

![imagen](https://user-images.githubusercontent.com/7296281/151617771-f1e2e62c-306f-4eb7-bb10-1c448387b9a5.png)
