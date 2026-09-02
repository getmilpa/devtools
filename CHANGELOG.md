# Changelog


## [0.24.0](https://github.com/getmilpa/devtools/compare/v0.23.0...v0.24.0) (2026-09-02)


### Features

* implement declares it creates its named target (createsNamedTarget) ([#69](https://github.com/getmilpa/devtools/issues/69)) ([24ac8c8](https://github.com/getmilpa/devtools/commit/24ac8c811bd75f72fdea9d4ecc2ecb24b242715e))

## [0.23.0](https://github.com/getmilpa/devtools/compare/v0.22.0...v0.23.0) (2026-09-02)


### Features

* **implement:** stage piece-wise partials off the bootable file, publish atomically ([#67](https://github.com/getmilpa/devtools/issues/67)) ([311ffdb](https://github.com/getmilpa/devtools/commit/311ffdb8220af83fc24f5ec795c4d94446515846))

## [0.22.0](https://github.com/getmilpa/devtools/compare/v0.21.1...v0.22.0) (2026-09-02)


### Features

* **implement:** cap inline content and open the piece-wise door (start/append/finish) ([#65](https://github.com/getmilpa/devtools/issues/65)) ([e886d62](https://github.com/getmilpa/devtools/commit/e886d624da45eda60c0a20cbcf23382c714a5e05))

## [0.21.1](https://github.com/getmilpa/devtools/compare/v0.21.0...v0.21.1) (2026-09-02)


### Bug Fixes

* make runtime belongsTo errors honest ([#63](https://github.com/getmilpa/devtools/issues/63)) ([8e814b5](https://github.com/getmilpa/devtools/commit/8e814b5f4c37ca36e0d64b32dc33321bb0e1814f))
* **make:** do not double the Test suffix or teach a circular judge cycle ([#62](https://github.com/getmilpa/devtools/issues/62)) ([afe3613](https://github.com/getmilpa/devtools/commit/afe3613d90771116ee5675bfa2d704946bdf63ac))

## [0.21.0](https://github.com/getmilpa/devtools/compare/v0.20.0...v0.21.0) (2026-09-01)


### Features

* discover — finding has one shape ([#60](https://github.com/getmilpa/devtools/issues/60)) ([f5dee13](https://github.com/getmilpa/devtools/commit/f5dee13563cb9fbd37f45e653afb1196752ea8f6))

## [0.20.0](https://github.com/getmilpa/devtools/compare/v0.19.0...v0.20.0) (2026-09-01)


### Features

* **contract:** make and test declare their contracts, postcondition names under one authority ([#58](https://github.com/getmilpa/devtools/issues/58)) ([b0c8c6b](https://github.com/getmilpa/devtools/commit/b0c8c6ba0bafb874ee5c0ea381d9c8202098e5ae))

## [0.19.0](https://github.com/getmilpa/devtools/compare/v0.18.0...v0.19.0) (2026-09-01)


### Features

* **devtools:** universal contract introspection reaching app, vendor, and source ([#54](https://github.com/getmilpa/devtools/issues/54)) ([44ce668](https://github.com/getmilpa/devtools/commit/44ce66812e4fa0a0e84224659f7072b093583bb1))
* **make:** generator closure — materialize known postconditions, and make:resource ([#56](https://github.com/getmilpa/devtools/issues/56)) ([766fea0](https://github.com/getmilpa/devtools/commit/766fea00c7f818468b167fb44c1f32e6599a8be3))

## [0.18.0](https://github.com/getmilpa/devtools/compare/v0.17.0...v0.18.0) (2026-09-01)


### Features

* **devtools:** test baseline and delta so a regression can be told from a pre-existing failure ([#52](https://github.com/getmilpa/devtools/issues/52)) ([935145b](https://github.com/getmilpa/devtools/commit/935145b70e20fbfb54674ac4202279d4940dccc1))

## [0.17.0](https://github.com/getmilpa/devtools/compare/v0.16.0...v0.17.0) (2026-09-01)


### Features

* **devtools:** add read-only test and artifact discovery ([#50](https://github.com/getmilpa/devtools/issues/50)) ([a37c4e4](https://github.com/getmilpa/devtools/commit/a37c4e4411e94273ec69037801f238cd579614be))
* **make:** strong postconditions so ok:true means the consequences exist ([#49](https://github.com/getmilpa/devtools/issues/49)) ([f98ce4a](https://github.com/getmilpa/devtools/commit/f98ce4abb492f140da9d6e84c53626f17ac86bc5))

## [0.16.0](https://github.com/getmilpa/devtools/compare/v0.15.4...v0.16.0) (2026-08-31)


### Features

* **devtools:** close the agent's introspection debt — enum-as-consequence + artifact:contract ([#47](https://github.com/getmilpa/devtools/issues/47)) ([e4ec0e8](https://github.com/getmilpa/devtools/commit/e4ec0e89e498637b62020980d2eed49260457684))

## [0.15.4](https://github.com/getmilpa/devtools/compare/v0.15.3...v0.15.4) (2026-08-31)


### Bug Fixes

* **make:** document the fields DSL and the plugin identifier in the schema ([#45](https://github.com/getmilpa/devtools/issues/45)) ([677fd43](https://github.com/getmilpa/devtools/commit/677fd433dfa90f480bf61bfdb1f19f39edce539d))

## [0.15.3](https://github.com/getmilpa/devtools/compare/v0.15.2...v0.15.3) (2026-08-30)


### Bug Fixes

* implement resolves the file's namespace instead of refusing a foreign one ([#43](https://github.com/getmilpa/devtools/issues/43)) ([78f004c](https://github.com/getmilpa/devtools/commit/78f004c7ade4d5df1bfa73dfe29b0d9efca1dd26))

## [0.14.3](https://github.com/getmilpa/devtools/releases/tag/v0.14.3) (2026-08-12)

Accepts `milpa/command ^0.8`, which ships the descent field — an argument that lowers an operation's ceiling, with its reason carried in the declaration.

Widening only: no behaviour changes here. Capping the atom at `^0.7` is what stopped anything downstream from resolving a version that uses it; greenhouse `evidence/0151` measured eight packages holding that cap.

## [0.9.9](https://github.com/getmilpa/devtools/releases/tag/v0.9.9) (2026-08-04)

## [0.9.9](https://github.com/getmilpa/devtools/compare/v0.9.8...v0.9.9) (2026-08-04)


### Bug Fixes

* **deps:** ensanchar el pin de milpa/plugin a ^0.9 ([16cd14b](https://github.com/getmilpa/devtools/commit/16cd14be28d298b356536cd03364134fde9400ed))

## [0.9.8](https://github.com/getmilpa/devtools/releases/tag/v0.9.8) (2026-08-04)

## [0.9.8](https://github.com/getmilpa/devtools/compare/v0.9.7...v0.9.8) (2026-08-04)


### Bug Fixes

* **composer:** declarar type milpa-capability para que el paquete sea descubrible por lo que es ([9300b91](https://github.com/getmilpa/devtools/commit/9300b919e7b58f6fd79dd3bcb8e26e423e20ab35))

## [0.9.7](https://github.com/getmilpa/devtools/releases/tag/v0.9.7) (2026-08-02)

## [0.9.7](https://github.com/getmilpa/devtools/compare/v0.9.6...v0.9.7) (2026-08-02)


### Bug Fixes

* widen milpa/command and milpa/plugin pins to accept the 0.5/0.8 minors ([2c2f393](https://github.com/getmilpa/devtools/commit/2c2f393f043e78a0d060f062201e2a86343c4070))

## [0.9.6](https://github.com/getmilpa/devtools/releases/tag/v0.9.6) (2026-08-01)

## [0.9.6](https://github.com/getmilpa/devtools/compare/v0.9.5...v0.9.6) (2026-08-01)


### Bug Fixes

* the capability contract speaks English ([0db24ac](https://github.com/getmilpa/devtools/commit/0db24acb89d43379a6ec2fe30b3d2b74c19a7ba0))

## [0.9.5](https://github.com/getmilpa/devtools/releases/tag/v0.9.5) (2026-08-01)

## [0.9.5](https://github.com/getmilpa/devtools/compare/v0.9.4...v0.9.5) (2026-08-01)


### Bug Fixes

* este paquete declara que aporta ([87a5255](https://github.com/getmilpa/devtools/commit/87a52550509742ff7f40c26b4a610d677810fc31))

## [0.9.4](https://github.com/getmilpa/devtools/releases/tag/v0.9.4) (2026-08-01)

## [0.9.4](https://github.com/getmilpa/devtools/compare/v0.9.3...v0.9.4) (2026-08-01)


### Bug Fixes

* **deps:** el pin de milpa/command deja de ser una jaula de un minor ([abddab7](https://github.com/getmilpa/devtools/commit/abddab76f05f52053a259e178f2573fbe583a1c7))

## [0.9.3](https://github.com/getmilpa/devtools/releases/tag/v0.9.3) (2026-08-01)

## [0.9.3](https://github.com/getmilpa/devtools/compare/v0.9.2...v0.9.3) (2026-08-01)


### Bug Fixes

* **deps:** el pin de milpa/core deja de ser una jaula de un minor ([c559a25](https://github.com/getmilpa/devtools/commit/c559a2582a803f39d94b978f6fca6f50465c7473))

## [0.9.2](https://github.com/getmilpa/devtools/releases/tag/v0.9.2) (2026-07-31)

## [0.9.2](https://github.com/getmilpa/devtools/compare/v0.9.1...v0.9.2) (2026-07-31)


### Bug Fixes

* require milpa/plugin ^0.6 ([b20b787](https://github.com/getmilpa/devtools/commit/b20b78761d35a5aaa18947fbb4e60d0828c7b8cd))

## [0.9.1](https://github.com/getmilpa/devtools/releases/tag/v0.9.1) (2026-07-31)

## [0.9.1](https://github.com/getmilpa/devtools/compare/v0.9.0...v0.9.1) (2026-07-31)


### Bug Fixes

* require milpa/plugin ^0.5 ([4613df5](https://github.com/getmilpa/devtools/commit/4613df57292785c2163d2fc45fb1abf615364797))

## [0.9.0](https://github.com/getmilpa/devtools/releases/tag/v0.9.0) (2026-07-30)

## [0.9.0](https://github.com/getmilpa/devtools/compare/v0.8.0...v0.9.0) (2026-07-30)


### Features

* publish validate and make as declared Operations ([7fdd424](https://github.com/getmilpa/devtools/commit/7fdd424a6b2e2b572b2592d14b1a78cc6986f310))

## [0.8.0](https://github.com/getmilpa/devtools/releases/tag/v0.8.0) (2026-07-30)

## [0.8.0](https://github.com/getmilpa/devtools/compare/v0.7.1...v0.8.0) (2026-07-30)


### ⚠ BREAKING CHANGES

* the constraint on `milpa/plugin` moves from ^0.3 to ^0.4.

### Features

* require milpa/plugin ^0.4 ([eb10641](https://github.com/getmilpa/devtools/commit/eb10641246849d2d472a5a9540cb1a4b8f6aa3fb))

## [0.7.1](https://github.com/getmilpa/devtools/releases/tag/v0.7.1) (2026-07-30)

## [0.7.1](https://github.com/getmilpa/devtools/compare/v0.7.0...v0.7.1) (2026-07-30)


### Bug Fixes

* catch up with the family's published versions ([ce3de77](https://github.com/getmilpa/devtools/commit/ce3de7701b924cc8cbf1d892f7f3570967b5e0eb))

## [0.7.0](https://github.com/getmilpa/devtools/releases/tag/v0.7.0) (2026-07-28)

## [0.7.0](https://github.com/getmilpa/devtools/compare/v0.6.0...v0.7.0) (2026-07-28)


### Features

* a boundary rule that asks which package owns a class ([915fb1d](https://github.com/getmilpa/devtools/commit/915fb1d56bb0f3d8dd6b5473e28ccbe32dfa5be4))
* el tablero de la familia y el validador de paridad de metadatos ([b49fa05](https://github.com/getmilpa/devtools/commit/b49fa050d70d22f2e70ca561bdea80cc5f3cd90f))


### Bug Fixes

* sacar el tablero de la familia — es del host, no de este paquete ([b18d362](https://github.com/getmilpa/devtools/commit/b18d362c888d1b469b88493d3561933f92c26bb5))

## [0.6.0](https://github.com/getmilpa/devtools/releases/tag/v0.6.0) (2026-07-14)

## [0.6.0](https://github.com/getmilpa/devtools/compare/v0.5.0...v0.6.0) (2026-07-14)


### Features

* make:entity scaffolds driver-aware storage ([3c5bb66](https://github.com/getmilpa/devtools/commit/3c5bb661f9b77ea2740e09fb0ab329426cf56029))

## [0.5.0](https://github.com/getmilpa/devtools/releases/tag/v0.5.0) (2026-07-09)

## [0.5.0](https://github.com/getmilpa/devtools/compare/v0.4.0...v0.5.0) (2026-07-09)


### Features

* generators auto-wire into an existing plugin + make:tool --needs ([14c2d45](https://github.com/getmilpa/devtools/commit/14c2d459dc3d761bea7adad15d33cdff097250d3))


### Miscellaneous Chores

* release 0.5.0 ([eb89a31](https://github.com/getmilpa/devtools/commit/eb89a317b783e3d0a9b47346b8297551264c8f90))

## [0.4.0](https://github.com/getmilpa/devtools/releases/tag/v0.4.0) (2026-07-09)

## [0.4.0](https://github.com/getmilpa/devtools/compare/v0.3.0...v0.4.0) (2026-07-09)


### Features

* the full make surface — make:plugin/service/tool/crud ([cc9a3e1](https://github.com/getmilpa/devtools/commit/cc9a3e1c3de6ee3faa6f6911944934fe675a52bb))


### Miscellaneous Chores

* release 0.4.0 ([d87b5f7](https://github.com/getmilpa/devtools/commit/d87b5f704b2112dfc4cf3ecf364e435ad94e5092))

## [0.3.0](https://github.com/getmilpa/devtools/releases/tag/v0.3.0) (2026-07-09)

## [0.3.0](https://github.com/getmilpa/devtools/compare/v0.2.0...v0.3.0) (2026-07-09)


### Features

* make:entity runtime-aware — scaffold a persisting entity ([9481687](https://github.com/getmilpa/devtools/commit/9481687285763a9786a74de55ba6e57a8fecc101))


### Miscellaneous Chores

* release 0.3.0 ([8791f96](https://github.com/getmilpa/devtools/commit/8791f96a89406e37e8c8d60b0ff93e2b1308f2dc))

## [0.2.0](https://github.com/getmilpa/devtools/releases/tag/v0.2.0) (2026-07-09)

## [0.2.0](https://github.com/getmilpa/devtools/compare/v0.1.0...v0.2.0) (2026-07-09)


### Features

* runtime-aware scaffolding + optional Doctrine (+ license gate) ([d363cb9](https://github.com/getmilpa/devtools/commit/d363cb9b2a43c3c3d08e8ac9511841212ccd64d0))


### Miscellaneous Chores

* release 0.2.0 ([c09ba70](https://github.com/getmilpa/devtools/commit/c09ba7057495fc18c0685c270fc70d4546e0c0ce))

## [0.14.2](https://github.com/getmilpa/devtools/compare/v0.14.1...v0.14.2) (2026-08-12)


### Bug Fixes

* accept milpa/plugin ^0.11, the second leaf blocking the family ([#32](https://github.com/getmilpa/devtools/issues/32)) ([2537c70](https://github.com/getmilpa/devtools/commit/2537c70e81019ca779dc1fd4a2a0f03c0f14cba0))

## [0.14.1](https://github.com/getmilpa/devtools/compare/v0.14.0...v0.14.1) (2026-08-11)


### Bug Fixes

* require milpa/command ^0.7, the only range this package can run in ([#30](https://github.com/getmilpa/devtools/issues/30)) ([c9a97df](https://github.com/getmilpa/devtools/commit/c9a97dffc9775c99acf73352753e495b7ce929bd))

## [0.14.0](https://github.com/getmilpa/devtools/compare/v0.13.1...v0.14.0) (2026-08-09)


### Features

* **effect:** the dev tools declare their ceiling, so scaffolding stays usable ([fe4dde3](https://github.com/getmilpa/devtools/commit/fe4dde341a1996ceb3e50744b164d6f71059b46a))

## [0.13.1](https://github.com/getmilpa/devtools/compare/v0.13.0...v0.13.1) (2026-08-09)


### Bug Fixes

* **deps:** reach milpa/command ^0.7, where the ceiling grew a fifth dimension ([4a78fbd](https://github.com/getmilpa/devtools/commit/4a78fbd732f94ba26aad531be796646a4921acf0))

## [0.13.0](https://github.com/getmilpa/devtools/compare/v0.12.0...v0.13.0) (2026-08-06)


### Features

* the behavioural judge in the landing gate, and make test to scaffold it ([e591a8e](https://github.com/getmilpa/devtools/commit/e591a8e48aae6e954da8802ea4497334570e6389))

## [0.12.0](https://github.com/getmilpa/devtools/compare/v0.11.2...v0.12.0) (2026-08-06)


### Features

* implement and edit — the agent writes code bodies through a gate, not around it ([d199ba5](https://github.com/getmilpa/devtools/commit/d199ba531aaae11c846b92c60c4180b0834a4192))

## [0.11.2](https://github.com/getmilpa/devtools/compare/v0.11.1...v0.11.2) (2026-08-05)


### Bug Fixes

* **deps:** el rango de milpa/plugin admite 0.10 ([bd9b664](https://github.com/getmilpa/devtools/commit/bd9b6641f40ee4e81aeb06dc5964e8db395e89a9))

## [0.11.1](https://github.com/getmilpa/devtools/compare/v0.11.0...v0.11.1) (2026-08-05)


### Bug Fixes

* **deps:** el rango de milpa/command admite 0.6 ([bfc3249](https://github.com/getmilpa/devtools/commit/bfc3249786a9a9123b8c0f656172672da4859d69))

## [0.11.0](https://github.com/getmilpa/devtools/compare/v0.10.0...v0.11.0) (2026-08-04)


### Features

* **doctor:** coa update — actualizar una app sin tocar composer ([668ff55](https://github.com/getmilpa/devtools/commit/668ff55e9cd98187109067085fb221af029996a9))

## [0.10.0](https://github.com/getmilpa/devtools/compare/v0.9.9...v0.10.0) (2026-08-04)


### Features

* **doctor:** Doctor\Repair — la reparación vive junto al diagnóstico ([a253fe1](https://github.com/getmilpa/devtools/commit/a253fe15b3c421eaff30467eec6408d38d2f3edd))

## [0.9.9](https://github.com/getmilpa/devtools/compare/v0.9.8...v0.9.9) (2026-08-04)


### Bug Fixes

* **deps:** ensanchar el pin de milpa/plugin a ^0.9 ([16cd14b](https://github.com/getmilpa/devtools/commit/16cd14be28d298b356536cd03364134fde9400ed))

## [0.9.8](https://github.com/getmilpa/devtools/compare/v0.9.7...v0.9.8) (2026-08-04)


### Bug Fixes

* **composer:** declarar type milpa-capability para que el paquete sea descubrible por lo que es ([9300b91](https://github.com/getmilpa/devtools/commit/9300b919e7b58f6fd79dd3bcb8e26e423e20ab35))

## [0.9.7](https://github.com/getmilpa/devtools/compare/v0.9.6...v0.9.7) (2026-08-02)


### Bug Fixes

* widen milpa/command and milpa/plugin pins to accept the 0.5/0.8 minors ([2c2f393](https://github.com/getmilpa/devtools/commit/2c2f393f043e78a0d060f062201e2a86343c4070))

## [0.9.6](https://github.com/getmilpa/devtools/compare/v0.9.5...v0.9.6) (2026-08-01)


### Bug Fixes

* the capability contract speaks English ([0db24ac](https://github.com/getmilpa/devtools/commit/0db24acb89d43379a6ec2fe30b3d2b74c19a7ba0))

## [0.9.5](https://github.com/getmilpa/devtools/compare/v0.9.4...v0.9.5) (2026-08-01)


### Bug Fixes

* este paquete declara que aporta ([87a5255](https://github.com/getmilpa/devtools/commit/87a52550509742ff7f40c26b4a610d677810fc31))

## [0.9.4](https://github.com/getmilpa/devtools/compare/v0.9.3...v0.9.4) (2026-08-01)


### Bug Fixes

* **deps:** el pin de milpa/command deja de ser una jaula de un minor ([abddab7](https://github.com/getmilpa/devtools/commit/abddab76f05f52053a259e178f2573fbe583a1c7))

## [0.9.3](https://github.com/getmilpa/devtools/compare/v0.9.2...v0.9.3) (2026-08-01)


### Bug Fixes

* **deps:** el pin de milpa/core deja de ser una jaula de un minor ([c559a25](https://github.com/getmilpa/devtools/commit/c559a2582a803f39d94b978f6fca6f50465c7473))

## [0.9.2](https://github.com/getmilpa/devtools/compare/v0.9.1...v0.9.2) (2026-07-31)


### Bug Fixes

* require milpa/plugin ^0.6 ([b20b787](https://github.com/getmilpa/devtools/commit/b20b78761d35a5aaa18947fbb4e60d0828c7b8cd))

## [0.9.1](https://github.com/getmilpa/devtools/compare/v0.9.0...v0.9.1) (2026-07-31)


### Bug Fixes

* require milpa/plugin ^0.5 ([4613df5](https://github.com/getmilpa/devtools/commit/4613df57292785c2163d2fc45fb1abf615364797))

## [0.9.0](https://github.com/getmilpa/devtools/compare/v0.8.0...v0.9.0) (2026-07-30)


### Features

* publish validate and make as declared Operations ([7fdd424](https://github.com/getmilpa/devtools/commit/7fdd424a6b2e2b572b2592d14b1a78cc6986f310))

## [0.8.0](https://github.com/getmilpa/devtools/compare/v0.7.1...v0.8.0) (2026-07-30)


### ⚠ BREAKING CHANGES

* the constraint on `milpa/plugin` moves from ^0.3 to ^0.4.

### Features

* require milpa/plugin ^0.4 ([eb10641](https://github.com/getmilpa/devtools/commit/eb10641246849d2d472a5a9540cb1a4b8f6aa3fb))

## [0.7.1](https://github.com/getmilpa/devtools/compare/v0.7.0...v0.7.1) (2026-07-30)


### Bug Fixes

* catch up with the family's published versions ([ce3de77](https://github.com/getmilpa/devtools/commit/ce3de7701b924cc8cbf1d892f7f3570967b5e0eb))

## [0.7.0](https://github.com/getmilpa/devtools/compare/v0.6.0...v0.7.0) (2026-07-28)


### Features

* a boundary rule that asks which package owns a class ([915fb1d](https://github.com/getmilpa/devtools/commit/915fb1d56bb0f3d8dd6b5473e28ccbe32dfa5be4))
* el tablero de la familia y el validador de paridad de metadatos ([b49fa05](https://github.com/getmilpa/devtools/commit/b49fa050d70d22f2e70ca561bdea80cc5f3cd90f))


### Bug Fixes

* sacar el tablero de la familia — es del host, no de este paquete ([b18d362](https://github.com/getmilpa/devtools/commit/b18d362c888d1b469b88493d3561933f92c26bb5))

## [0.6.0](https://github.com/getmilpa/devtools/compare/v0.5.0...v0.6.0) (2026-07-14)


### Features

* make:entity scaffolds driver-aware storage ([3c5bb66](https://github.com/getmilpa/devtools/commit/3c5bb661f9b77ea2740e09fb0ab329426cf56029))

## [0.5.0](https://github.com/getmilpa/devtools/compare/v0.4.0...v0.5.0) (2026-07-09)


### Features

* generators auto-wire into an existing plugin + make:tool --needs ([14c2d45](https://github.com/getmilpa/devtools/commit/14c2d459dc3d761bea7adad15d33cdff097250d3))


### Miscellaneous Chores

* release 0.5.0 ([eb89a31](https://github.com/getmilpa/devtools/commit/eb89a317b783e3d0a9b47346b8297551264c8f90))

## [0.4.0](https://github.com/getmilpa/devtools/compare/v0.3.0...v0.4.0) (2026-07-09)


### Features

* the full make surface — make:plugin/service/tool/crud ([cc9a3e1](https://github.com/getmilpa/devtools/commit/cc9a3e1c3de6ee3faa6f6911944934fe675a52bb))


### Miscellaneous Chores

* release 0.4.0 ([d87b5f7](https://github.com/getmilpa/devtools/commit/d87b5f704b2112dfc4cf3ecf364e435ad94e5092))

## [0.3.0](https://github.com/getmilpa/devtools/compare/v0.2.0...v0.3.0) (2026-07-09)


### Features

* make:entity runtime-aware — scaffold a persisting entity ([9481687](https://github.com/getmilpa/devtools/commit/9481687285763a9786a74de55ba6e57a8fecc101))


### Miscellaneous Chores

* release 0.3.0 ([8791f96](https://github.com/getmilpa/devtools/commit/8791f96a89406e37e8c8d60b0ff93e2b1308f2dc))

## [0.2.0](https://github.com/getmilpa/devtools/compare/v0.1.0...v0.2.0) (2026-07-09)


### Features

* runtime-aware scaffolding + optional Doctrine (+ license gate) ([d363cb9](https://github.com/getmilpa/devtools/commit/d363cb9b2a43c3c3d08e8ac9511841212ccd64d0))


### Miscellaneous Chores

* release 0.2.0 ([c09ba70](https://github.com/getmilpa/devtools/commit/c09ba7057495fc18c0685c270fc70d4546e0c0ce))

## 0.1.0 (2026-07-08)


### Features

* milpa/devtools initial public release ([5ebed48](https://github.com/getmilpa/devtools/commit/5ebed48844696b746f061fff79f8314e4dfd237e))


### Miscellaneous Chores

* release 0.1.0 ([f844cf9](https://github.com/getmilpa/devtools/commit/f844cf97d10da8b67b888b10c1d48add46fed2a1))
