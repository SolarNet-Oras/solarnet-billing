FROM debian:bookworm-slim

RUN apt-get update \
 && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
      ca-certificates freeradius freeradius-postgresql freeradius-utils \
 && rm -rf /var/lib/apt/lists/* \
 && rm -f /etc/freeradius/3.0/sites-enabled/default /etc/freeradius/3.0/sites-enabled/inner-tunnel \
 && rm -f /etc/freeradius/3.0/mods-enabled/sql

# The files define a DHCP/IPoE-only server. There is no HotSpot, PPP, EAP,
# shell, REST, exec, or public listener configuration in this image.
COPY deploy/freeradius/sql /etc/freeradius/3.0/mods-enabled/sql
COPY deploy/freeradius/solarnet-ipoe /etc/freeradius/3.0/sites-enabled/solarnet-ipoe
COPY deploy/freeradius/clients.conf /etc/freeradius/3.0/clients.conf
COPY deploy/freeradius/dictionary /etc/freeradius/3.0/dictionary
COPY deploy/freeradius-entrypoint.sh /usr/local/bin/solarnet-freeradius

RUN chmod 0755 /usr/local/bin/solarnet-freeradius \
 && chown -R freerad:freerad /etc/freeradius/3.0

EXPOSE 1812/udp 1813/udp
ENTRYPOINT ["/usr/local/bin/solarnet-freeradius"]
