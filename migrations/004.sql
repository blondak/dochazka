CREATE TABLE medical_proof (
    request_id bigint PRIMARY KEY REFERENCES request,
    jpeg_base64 text NOT NULL
);
