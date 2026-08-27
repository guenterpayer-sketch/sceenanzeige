#!/usr/bin/env python3
"""
03_windows_launcher/icon-bauen.py

Erzeugt assets/img/monitor-launcher.ico — das Symbol der Windows-Verknuepfung
"Monitor Saal N". Bewusst ohne Bildbibliothek (kein Pillow, kein ImageMagick):
Das Motiv ist geometrisch, PNG + ICO sind mit zlib/struct direkt schreibbar.
So laesst sich das Icon in jeder Umgebung reproduzieren.

Motiv: Bildschirm in Markenrot #ad2121, helle Anzeigeflaeche, unten der rote
Ticker-Streifen (wie im Monitor-Frontend). Gerendert mit 4x-Supersampling.

Aufruf:  python3 03_windows_launcher/icon-bauen.py
"""

import struct
import zlib

ROT = (0xAD, 0x21, 0x21)
HELL = (0xF2, 0xF0, 0xEE)
DUNKEL = (0x1A, 0x1A, 0x1A)

S = 4              # Supersampling-Faktor
N = 256            # Kantenlaenge des groessten Icons
W = N * S


def rounded_rect(x0, y0, x1, y1, r):
    """Praedikat: liegt (x, y) im abgerundeten Rechteck?"""
    def inside(x, y):
        if not (x0 <= x <= x1 and y0 <= y <= y1):
            return False
        for cx, cy in ((x0 + r, y0 + r), (x1 - r, y0 + r),
                       (x0 + r, y1 - r), (x1 - r, y1 - r)):
            if (x < x0 + r or x > x1 - r) and (y < y0 + r or y > y1 - r):
                if abs(x - cx) <= r and abs(y - cy) <= r:
                    return (x - cx) ** 2 + (y - cy) ** 2 <= r * r
        return True
    return inside


# --- Motiv in 256er-Koordinaten, hochskaliert auf W -------------------------
def sc(v):
    return v * S


gehaeuse = rounded_rect(sc(14), sc(34), sc(242), sc(196), sc(20))
flaeche  = rounded_rect(sc(32), sc(52), sc(224), sc(178), sc(8))
ticker   = rounded_rect(sc(32), sc(156), sc(224), sc(178), 0)
fuss     = rounded_rect(sc(104), sc(196), sc(152), sc(220), sc(2))
sockel   = rounded_rect(sc(70), sc(218), sc(186), sc(240), sc(10))


def farbe_bei(x, y):
    """Liefert (r, g, b, a) fuer einen Supersample-Punkt."""
    if ticker(x, y):
        return ROT + (255,)
    if flaeche(x, y):
        return HELL + (255,)
    if gehaeuse(x, y):
        return ROT + (255,)
    if fuss(x, y) or sockel(x, y):
        return ROT + (255,)
    return DUNKEL + (0,)


# --- Grossbild rendern ------------------------------------------------------
gross = bytearray(W * W * 4)
for y in range(W):
    zeile = y * W * 4
    for x in range(W):
        r, g, b, a = farbe_bei(x, y)
        i = zeile + x * 4
        gross[i] = r
        gross[i + 1] = g
        gross[i + 2] = b
        gross[i + 3] = a


def downsample(quelle, quell_kante, ziel_kante):
    """Box-Filter mit Alpha-Gewichtung (verhindert dunkle Halos)."""
    f = quell_kante // ziel_kante
    ziel = bytearray(ziel_kante * ziel_kante * 4)
    flaeche_px = f * f
    for zy in range(ziel_kante):
        for zx in range(ziel_kante):
            sr = sg = sb = sa = 0
            for dy in range(f):
                basis = ((zy * f + dy) * quell_kante + zx * f) * 4
                for dx in range(f):
                    i = basis + dx * 4
                    a = quelle[i + 3]
                    sr += quelle[i] * a
                    sg += quelle[i + 1] * a
                    sb += quelle[i + 2] * a
                    sa += a
            j = (zy * ziel_kante + zx) * 4
            if sa:
                ziel[j] = sr // sa
                ziel[j + 1] = sg // sa
                ziel[j + 2] = sb // sa
            ziel[j + 3] = sa // flaeche_px
    return ziel


def png(rgba, kante):
    """Minimaler PNG-Writer (RGBA, Filter 0)."""
    roh = bytearray()
    for y in range(kante):
        roh.append(0)
        roh += rgba[y * kante * 4:(y + 1) * kante * 4]

    def chunk(typ, daten):
        return (struct.pack('>I', len(daten)) + typ + daten
                + struct.pack('>I', zlib.crc32(typ + daten) & 0xFFFFFFFF))

    ihdr = struct.pack('>IIBBBBB', kante, kante, 8, 6, 0, 0, 0)
    return (b'\x89PNG\r\n\x1a\n'
            + chunk(b'IHDR', ihdr)
            + chunk(b'IDAT', zlib.compress(bytes(roh), 9))
            + chunk(b'IEND', b''))


GROESSEN = [256, 48, 32, 16]
bilder = []
for g in GROESSEN:
    bilder.append((g, png(downsample(gross, W, g), g)))

# --- ICO zusammensetzen (PNG-Eintraege, ab Windows Vista unterstuetzt) ------
kopf = struct.pack('<HHH', 0, 1, len(bilder))
offset = 6 + 16 * len(bilder)
eintraege = b''
daten = b''
for g, roh in bilder:
    eintraege += struct.pack('<BBBBHHII',
                             0 if g == 256 else g, 0 if g == 256 else g,
                             0, 0, 1, 32, len(roh), offset)
    daten += roh
    offset += len(roh)

ziel = '02_ordnerstruktur/screen.tcpayer.de/assets/img/monitor-launcher.ico'
with open(ziel, 'wb') as f:
    f.write(kopf + eintraege + daten)
print('geschrieben:', ziel, len(kopf + eintraege + daten), 'Bytes')
