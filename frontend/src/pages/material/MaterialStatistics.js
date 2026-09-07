import React, { useCallback, useEffect, useState } from "react";
import api from "../../api";
import {
    AnalyticsHeader,
    AnalyticsState,
    CHART_COLORS,
    ChartCard,
    ColumnChart,
    DonutChart,
    LineChart,
} from "../../components/statistics/AnalyticsCharts";

function MaterialStatistics() {
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);
    const [refreshing, setRefreshing] = useState(false);

    const load = useCallback(async () => {
        setRefreshing(true);
        setError(null);
        try {
            const response = await api.get("/material/statistics");
            setData(response.data);
        } catch (err) {
            console.error(err);
            setError("Could not load the content analytics.");
        } finally {
            setRefreshing(false);
        }
    }, []);

    useEffect(() => { load(); }, [load]);

    return (
        <div className="min-h-screen px-5 py-10 sm:px-8 lg:px-10 lg:py-16">
            <AnalyticsHeader
                title="Content Analytics"
                description="Visual analysis of review decisions, content composition, and learning-material readiness."
                updatedAt={data?.updated_at}
                onRefresh={load}
                refreshing={refreshing}
            />

            <AnalyticsState loading={!data && !error} error={error}>
                <div className="grid grid-cols-1 gap-6 xl:grid-cols-2">
                    <ChartCard
                        title="Content Review Activity – Last 8 Weeks"
                        description="Approved, rejected, and newly pending content recorded each week."
                    >
                        <LineChart data={data?.review_timeline} />
                    </ChartCard>

                    <ChartCard
                        title="Approved Learning Content Mix"
                        description="The relative amount of each approved learning-material type."
                    >
                        <DonutChart items={data?.content_mix ?? []} centerLabel="Items" />
                    </ChartCard>

                    <ChartCard
                        title="Review Status by Content Type"
                        description="Approved, pending, and rejected records for every managed content type."
                    >
                        <ColumnChart
                            items={data?.review_status ?? []}
                            series={[
                                { key: "approved", label: "Approved", color: CHART_COLORS[3] },
                                { key: "pending", label: "Pending", color: CHART_COLORS[2] },
                                { key: "rejected", label: "Rejected", color: "#ef4444" },
                            ]}
                        />
                    </ChartCard>

                    <ChartCard
                        title="Roadmap Material Coverage"
                        description="Percentage of standard modules that contain an approved tutorial, quiz, and project. The least complete roadmaps appear first."
                    >
                        <ColumnChart
                            items={data?.roadmap_coverage ?? []}
                            maximum={100}
                            series={[
                                { key: "coverage_percent", label: "Coverage %", color: CHART_COLORS[0] },
                            ]}
                        />
                    </ChartCard>
                </div>
            </AnalyticsState>
        </div>
    );
}

export default MaterialStatistics;
