CREATE TABLE gateway_enrollment_nonce (
    nonce text PRIMARY KEY,
    expires bigint NOT NULL
);

CREATE INDEX gateway_enrollment_nonce_expires ON gateway_enrollment_nonce(expires);
