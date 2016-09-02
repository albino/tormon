#!/usr/bin/perl -I /var/www/perl5/lib/perl5
use 5.010;
use strict;
use warnings;
use FCGI;
use Switch;
use File::Slurp;
use Template::Simple;
use Email::Valid;
use DBI;
use Math::Random::Secure qw(rand);
use Email::Sender::Simple qw(sendmail);
use Email::Simple;
use Email::Simple::Creator;
use Email::Sender::Transport::SMTPS;
use YAML::Tiny;
use POSIX;
use FindBin qw($Bin);

my $VERSION = "1.0";

sub logmsg {
  my $msg = shift;
  return strftime("%F %T", localtime $^T)." $msg";
}

sub rand_string {
  my $ret;
  my @alpha = "a".."z";
  for (1..16) {
    $ret .= $alpha[int(rand(26))];
  }
  return $ret;
}

my $dbh = DBI->connect("dbi:SQLite:dbname=/var/www/tormon.db", "", "");

my $tmpl = new Template::Simple (
  pre_delim  => "<%",
  post_delim => "%>",
);

my $config = YAML::Tiny->read("/var/www/tormon.yml")->[0] or die $!;

my $sock = FCGI::OpenSocket(
  "/var/www/run/tormon.sock",
  5,
);

my $request = FCGI::Request(
  \*STDIN,
  \*STDOUT,
  \*STDOUT,
  \%ENV,
  $sock,
  0,
);

say logmsg "tormon v$VERSION now accepting requests";

while ($request->Accept() <= 0) {
  my $content;
  my $code;

  switch ($ENV{"REQUEST_URI"}) {
    case "/" {
      my $tt = read_file("$Bin/index.tt");
      $content = ${ $tmpl->render($tt, {version => $VERSION}) };
    }
    case "/subscribe" {
      read STDIN, my $buf, $ENV{"CONTENT_LENGTH"};
      my @pairs = split /&/, $buf;
      my %input;
      for (@pairs) {
        $_ =~ s/\+/ /g;
        $_ =~ s/%([a-fA-F0-9][a-fA-F0-9])/pack("C", hex($1))/eg;
        my ($a, $b) = split '=', $_;
        $input{$a} = $b;
      }

      if (!($input{"spam"} =~ m/London/i)) {
        $content = read_file("$Bin/e_security.tt");
        last;
      }
      if (!($input{"fp"} =~ m/^[A-F0-9]{40}$/)) {
        $content = read_file("$Bin/e_fingerprint.tt");
        last;
      }
      if (!Email::Valid->address($input{"email"})) {
        $content = read_file("$Bin/e_email.tt");
        last;
      }

      # Check if this email/fp combo is already in db
      my $sth = $dbh->prepare("select id from users where email=? and fp=?");
      $sth->bind_param(1, $input{"email"});
      $sth->bind_param(2, $input{"fp"});
      $sth->execute;
      my $href = $sth->fetchrow_hashref;
      if ($sth->rows != 0) {
        $content = read_file("$Bin/e_exists.tt");
        last;
      }
      $sth->finish;

      # Add the email to database
      my $secret = rand_string();
      $sth = $dbh->prepare("insert into users (email, confirmed, fp, secret)
                     values (?, 0, ?, ?);");
      $sth->bind_param(1, $input{"email"});
      $sth->bind_param(2, $input{"fp"});
      $sth->bind_param(3, $secret);
      $sth->execute;
      my $id = $dbh->last_insert_id("", "", "", "");

      # A confirmation email
      # TODO: async magic

      my $email = Email::Simple->create(
        header => [
          To => $input{"email"},
          From => '"Tor Relay Monitor" <tormon@tor.uptime.party>',
          Subject => "Confirm your email",
        ],
        body => "Hi,\n\nSomebody entered your email into the Tor relay monitor. If this was you, please click the link below to activate notifications.\n\n$config->{baseurl}/confirm?id=$id&s=$secret\n\nIf this wasn't you, just delete this email. If you'd like to contact the administrator, please send an email to albino\@autistici.org.\n",
      );
      my $trans = new Email::Sender::Transport::SMTPS (
        host => $config->{mail}->{host},
        port => $config->{mail}->{port},
        ssl => "starttls",
        sasl_username => $config->{mail}->{user},
        sasl_password => $config->{mail}->{password},
        debug => 0,
      );
      sendmail($email, {
        transport => $trans,
      });

      $content = read_file("$Bin/subscribe.tt");
    }
    case (/^\/confirm\?id=([0-9]+)&s=([a-z]{16})$/) {
      # limit scope or something
      if ($ENV{REQUEST_URI} =~ /^\/confirm\?id=([0-9]+)&s=([a-z]{16})$/) {
        my $id = $1;
        my $secret = $2;
        my $q = $dbh->prepare("select * from users where id=? and secret=?");
        $q->bind_param(1, $id);
        $q->bind_param(2, $secret);
        $q->execute;
        my $href = $q->fetchrow_hashref;
        
        if ($q->rows != 1) {
          $code = "Status: 403 Forbidden";
          my $tt = read_file("$Bin/error.tt");
          $content = ${ $tmpl->render($tt, {err => 403}) };
          last;
        }

        $q->finish;
        $q = $dbh->prepare("update users set confirmed=1 where id=? and secret=?");
        $q->bind_param(1, $id);
        $q->bind_param(2, $secret);
        $q->execute;
        $q->finish;

        $content = read_file("$Bin/confirm.tt");
      }
    }
    else {
      my $tt = read_file("$Bin/error.tt");
      $content = ${ $tmpl->render($tt, {err => 404}) };
      $code = "Status: 404 Not Found\n\n";
    }
  }

  my $tt = read_file("$Bin/wrapper.tt");
  my $html = $tmpl->render(
    $tt,
    {
      content => $content,
    },
  );
  $code = "\n" unless defined $code;
  print "Content-Type: text/html\n", $code, ${$html};
}
