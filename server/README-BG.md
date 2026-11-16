# Ubuntu Server → GoogieHost: ежедневно обновяване

Ubuntu изтегля данните чрез съществуващия PHP backend, записва локален snapshot и качва само `snapshot.json` в GoogieHost чрез **explicit FTPS на порт 21**. Не е необходим cron в GoogieHost, регистриран домейн или работещ браузър. Ubuntu трябва да е включен и свързан с интернет. Сайтът и частната папка първо трябва да са качени според основния README.

Ползваме systemd timer вместо cron: стартира всеки ден от **06:00 Europe/Sofia**, отчита лятното часово време и наваксва след пропуснат старт. Завършването е по-късно от 06:00 според връзката. Данните се качват след завършен цикъл. Не настройвайте едновременно този timer и друг collector за същите локални файлове.

## 1. Инсталиране на Ubuntu (с администраторски потребител)

```bash
sudo apt update
sudo apt install -y git php-cli php-curl python3 ca-certificates
sudo timedatectl set-ntp true
sudo useradd --system --user-group --home-dir /var/lib/little-steps --shell /usr/sbin/nologin little-steps
sudo git clone https://github.com/visitter/sofia-garden-finder.git /opt/sofia-garden-finder
sudo install -d -o little-steps -g little-steps -m 0750 /var/lib/little-steps
sudo install -m 0600 /opt/sofia-garden-finder/server/config.example /etc/little-steps.env
sudo nano /etc/little-steps.env
```

Ако потребителят или директорията вече съществуват, пропуснете съответната команда. Кодът остава собственост на root и не е достъпен за запис от услугата.

## 2. FTPS настройки

В GoogieHost → **FTP Management** вижте hostname, потребител и права на акаунта. Попълнете `/etc/little-steps.env` локално на Ubuntu; не изпращайте паролата в чат и не я записвайте в GitHub.

- `FTP_HOST`: действителното FTP име с валиден TLS сертификат. Не използвайте IP, ако сертификатът не съвпада.
- `FTP_USER`, `FTP_PASSWORD`: FTP данните на акаунта.
- `FTP_REMOTE_DIR`: папката с `snapshot.json`, видима за този FTP акаунт. Примерният `/domains/…` път може да се различава или акаунтът да е ограничен само до `public_html`. Нужен е достъп до частната папка **извън** `public_html`.
- Паролата може да е в единични кавички, както в шаблона. Ако съдържа единична кавичка, използвайте подходящото quoting за systemd EnvironmentFile. Това е файл с настройки, а не shell скрипт.

Не се допуска fallback към некриптиран FTP. При TLS грешка поправете hostname/сертификата; не изключвайте проверката. FTPS достъпът, правата за четене, запис и преименуване трябва да се проверят с реалния акаунт.

## 3. Инсталиране и първи реален тест

```bash
sudo install -m 0644 /opt/sofia-garden-finder/server/little-steps.service /etc/systemd/system/
sudo install -m 0644 /opt/sofia-garden-finder/server/little-steps.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl start little-steps.service
sudo journalctl -u little-steps.service -n 50 --no-pager
```

Първият старт може да отнеме няколко минути. Успехът включва съобщение `FTPS snapshot published and transfer checksum verified`. Ако данните за отделни заведения не се изтеглят, старите стойности се запазват, когато са налични, и сайтът показва предупреждение. Първоначално на Ubuntu няма стар snapshot; неуспешни нови записи са с неизвестна наличност.

След успех отворете сайта и натиснете „Зареди данните“. Датата под всяко заведение трябва да показва последната му успешна проверка. После включете графика:

```bash
sudo systemctl enable --now little-steps.timer
systemctl list-timers little-steps.timer --all
```

Не е необходимо да изпълнявате услугата всяка минута. PHP скриптът обработва партидите последователно в рамките на едно изпълнение на Ubuntu. Повторен ръчен старт в същия ден използва вече завършения дневен набор и опитва качването отново.

## Как се пазят данните

Новият файл първо се качва под временно име, изтегля се обратно и се сравнява SHA-256. След това се преименува на `snapshot.json`. Ако сървърът не поддържа подмяна чрез rename, качването се отчита като неуспешно; старият файл не се изтрива предварително. Проверете правата/съвместимостта с GoogieHost. Няма обещание за успешно качване преди този реален тест.

Локалното състояние е в `/var/lib/little-steps`, извън Git. При грешка в списъка или FTPS услугата приключва с неуспех; предишните публикувани данни остават. FTPS се опитва три пъти през 30 секунди. Логовете са в journal. След поправяне на проблем: `sudo systemctl start little-steps.service`.

## Поддръжка

```bash
sudo git -C /opt/sofia-garden-finder pull --ff-only
sudo systemctl status little-steps.timer
sudo journalctl -u little-steps.service --since today --no-pager
```

Тестове без хостинг данни: `php tests/backend.php` и `python3 -m unittest discover -s tests -p 'test_*.py'` от корена на проекта.

Старата ChatGPT автоматизация за отчет е отделна. Тя не обновява GoogieHost и не е нужна за тази услуга; не е изключена автоматично.
