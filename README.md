```sh
pkg_add curl # if you don't have it already
mv /var/www /var/www.old # clean out OpenBSD's default guff
mkdir -p /var/www/logs /var/www/run /var/www/perl5 /var/www/tormon
chown www:www /var/www/run
install -o www -g www -m 0400 httpd.conf /etc/
echo "permit nopass root as www" >> /etc/doas.conf
curl -L https://cpanmin.us | perl - App::cpanminus
cpanm -l /var/www/perl5 FCGI Switch Template::Simple File::Slurp Email::Valid
install -o www -g www -m 0500 tormon/* /var/www/tormon/
cat db.sql | sqlite3 /var/www/tormon.db
chown www:www /var/www/tormon.db
chmod 0600 /var/www/tormon.db
echo 'echo "Starting tormon" && doas -u www /var/www/tormon/tormon.fcgi &' >> /etc/rc.local
sh /etc/rc.local # assuming tormon is the only thing in rc.local
rcctl enable httpd
rcctl start httpd

# updating
install -o www -g www -m 0500 tormon/* /var/www/tormon/
```
