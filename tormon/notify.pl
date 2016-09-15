#!/usr/bin/perl -I /var/www/perl5/lib/perl5
use 5.010;
use strict;
use warnings;
use LWP::UserAgent;
use JSON::Tiny qw(decode_json);
use YAML::Tiny;
use DBI;
use Email::Sender::Simple qw(sendmail);
use Email::Simple;
use Email::Simple::Creator;
use Email::Sender::Transport::SMTPS;

my $onionoo = "https://onionoo.torproject.org";
my $config = YAML::Tiny->read("/var/www/tormon.yml")->[0] or die $!;

# get data from onionoo
my $ua = new LWP::UserAgent (
  timeout => 20,
  max_size => 16 * 1024**2,
);
$ua->agent("tormon ($ua->_agent) | for info/contact please write to albino AT autistici DOT org");

my $resp = $ua->get("$onionoo/details?fields=running,fingerprint,hashed_fingerprint");
die unless $resp->is_success;

my $onions = decode_json($resp->decoded_content);

# TODO: check the last updated date and only proceed if it is a newer list

# init db
my $dbh = DBI->connect("dbi:SQLite:dbname=/var/www/tormon.db", "", "") or die $!;

# get rows
my $sth = $dbh->prepare("select * from users");
$sth->execute;

SUB: while (my $sub = $sth->fetchrow_hashref) {
  next SUB unless $sub->{"confirmed"};
  my $status;

  RELAY: for my $relay (@{ $onions->{"relays"} }, @{ $onions->{"bridges"} }) {
    # check whether it's a relay or a bridge
    # for bridges, we need to read the hashed_fingerprint
    my $fp;
    if (defined $relay->{"fingerprint"}) {
      $fp = "fingerprint";
    } elsif (defined $relay->{"hashed_fingerprint"}) {
      $fp = "hashed_fingerprint";
    } else {
      warn "Relay has neither a `fingerprint` nor a `hashed_fingerprint` attribute!";
      next RELAY;
    }

    if ($sub->{"fp"} eq $relay->{$fp}) {
      # we have a match
      # is it up?

      if ($relay->{"running"}) {
        $status = 0;
      } else {
        $status = 1;
      }

      last RELAY;
    }
  }

  $status = 2 if !defined $status;

  if ($status > $sub->{"status"}) {
    # send email
    # TODO: async magic
    my $email = Email::Simple->create(
      header => [
        To => $sub->{"email"},
        From => '"Tor Relay Monitor" <' . $config->{"mail"}->{"from"} . '>',
        Subject => "Your Tor node is down!"
      ],
      body => "Hi,\n\nThe Tor node with the fingerprint '"
              . $sub->{"fp"} . "' " .
              ($status == 1 ? "is down." : "has disappeared from the Tor network!"),
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
  }

  if ($status != $sub->{"status"}) {
    # update status
    my $q = $dbh->prepare("update users set status=? where id=?");
    $q->bind_param(1, $status);
    $q->bind_param(2, $sub->{"id"});
    $q->execute;
    $q->finish;
  }
}

$sth->finish;
