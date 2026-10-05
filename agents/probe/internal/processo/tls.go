package processo

import "crypto/tls"
import "crypto/x509"

func tlsConfig(raizes *x509.CertPool) *tls.Config {
	return &tls.Config{MinVersion: tls.VersionTLS12, RootCAs: raizes}
}
