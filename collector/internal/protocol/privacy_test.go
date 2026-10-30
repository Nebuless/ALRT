package protocol

import (
	"encoding/json"
	"os"
	"reflect"
	"strings"
	"testing"
)

func TestDecodeRejectsSensitiveAndUnknownFields(t *testing.T) {
	for _, body := range []string{`{"protocol_version":1,"cmdline":"SECRET_CANARY"}`, `{"protocol_version":1,"processes":{"top_cpu":{"items":[{"cwd":"SECRET_CANARY"}]}}}`, `{"protocol_version":1,"metrics":{"environment":"SECRET_CANARY"}}`, `{"protocol_version":1} {"prompt":"SECRET_CANARY"}`} {
		_, err := Decode([]byte(body))
		if err == nil || strings.Contains(err.Error(), "SECRET_CANARY") {
			t.Fatalf("sensitive body accepted or leaked: %v", err)
		}
	}
	if _, err := Decode([]byte(`{"protocol_version":2}`)); err == nil || err.Error() != "protocol_version_unsupported" {
		t.Fatal("major version mismatch must be visible")
	}
}
func TestFixtureStrictDTO(t *testing.T) {
	raw, err := os.ReadFile("../../../contracts/fixtures/telemetry/valid-sample.json")
	if err != nil {
		t.Fatal(err)
	}
	report, err := Decode(raw)
	if err != nil {
		t.Fatal(err)
	}
	encoded, err := json.Marshal(report)
	if err != nil {
		t.Fatal(err)
	}
	var before, after any
	if json.Unmarshal(raw, &before) != nil || json.Unmarshal(encoded, &after) != nil || !reflect.DeepEqual(before, after) {
		t.Fatal("canonical fixture lost typed fields")
	}
}
