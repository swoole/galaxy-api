The synthetic `GeoIP2-Country-Test.mmdb` fixture comes from
https://github.com/maxmind/MaxMind-DB/blob/main/test-data/GeoIP2-Country-Test.mmdb
and is Copyright (c) 2013–2026 MaxMind, Inc. Used under the MIT license in
`LICENSE-MIT`. It contains fake example data and must never be used for real
geolocation. SHA-256 of the original fixture:
`fc12d6470b35b70bd3e4534dd433d3e262a6c73f7682f85c344e832d4190f02b`.

`WafDataFixtures::country()` updates only the four-byte build timestamp in a
test copy so age checks remain deterministic as this fixture ages. The record
tree and synthetic country results remain unchanged. Production import does
not modify timestamps.
